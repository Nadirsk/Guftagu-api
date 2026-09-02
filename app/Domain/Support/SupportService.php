<?php

namespace App\Domain\Support;

use App\Domain\Audit\AuditLogger;
use App\Models\AdminUser;
use App\Models\CannedReply;
use App\Models\Notification;
use App\Models\Report;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * GFT-142 / GFT-143 / GFT-144 — support tickets, SLA timers and escalation (epic B.4).
 *
 * Three rules the acceptance criteria turn on:
 *
 *  1. **B.4a — the first-response timer stops on the first staff reply, once.**
 *     `first_response_at` is written only if it is still null. An internal note does not
 *     stop it: the person waiting has not heard anything, so the promise has not been kept.
 *
 *  2. **B.4b — flagging a room creates a `high` priority report that names the Manager.**
 *     It goes through the same `reports` table the Moderator queue reads, so it appears
 *     there with no sync step. "Within 5 seconds" is satisfied by there being nothing to
 *     wait for.
 *
 *  3. **B.4c — escalation notifies the named Admin and is recorded on the ticket.** Both,
 *     not either: a notification with no record is unauditable, and a record with no
 *     notification is a message nobody reads.
 */
class SupportService
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    /**
     * Raise a ticket.
     *
     * The SLA minutes are **copied onto the row**, not read back from settings later. A
     * ticket has to be judged against the promise that applied when it was raised;
     * tightening the policy next month must not retroactively breach last month's tickets.
     *
     * @param  array<string, mixed>  $data
     */
    public function open(array $data, ?User $user, ?AdminUser $actor = null): SupportTicket
    {
        $priority = $data['priority'] ?? 'medium';

        $ticket = SupportTicket::create([
            'ref'         => SupportTicket::nextRef(),
            'user_id'     => $user?->id,
            'category'    => $data['category'] ?? 'other',
            'subject'     => $data['subject'],
            'description' => $data['description'],
            'attachments' => $data['attachments'] ?? null,
            'priority'    => $priority,
            'status'      => SupportTicket::OPEN,
            'sla_first_response_minutes' => $this->firstResponseSla($priority),
            'sla_resolution_minutes'     => $this->resolutionSla($priority),
        ]);

        // The opening description is the first message in the thread, so the conversation
        // view has one shape rather than "description, then messages".
        SupportTicketMessage::create([
            'ticket_id'   => $ticket->id,
            'sender_type' => $user !== null ? SupportTicketMessage::FROM_USER : SupportTicketMessage::FROM_SYSTEM,
            'sender_id'   => $user?->id,
            'body'        => $data['description'],
            'attachments' => $data['attachments'] ?? null,
        ]);

        if ($actor !== null) {
            $this->audit->log($actor, 'support.open', 'support', SupportTicket::class, $ticket->id, null, [
                'ref' => $ticket->ref, 'category' => $ticket->category,
            ]);
        }

        return $ticket;
    }

    /**
     * @throws SupportException
     */
    public function assign(SupportTicket $ticket, AdminUser $assignee, AdminUser $actor): SupportTicket
    {
        if (! $ticket->isOpen()) {
            throw new SupportException('BAD_REQUEST', "That ticket is already {$ticket->status}.", 400);
        }

        $before = ['assigned_to' => $ticket->assigned_to];

        $ticket->forceFill(['assigned_to' => $assignee->id, 'assigned_at' => now()])->save();

        $this->system($ticket, "Assigned to {$assignee->name}.");

        $this->audit->log($actor, 'support.assign', 'support', SupportTicket::class, $ticket->id, $before, [
            'assigned_to' => $assignee->id,
        ]);

        return $ticket->refresh();
    }

    /**
     * Reply — B.4a.
     *
     * @throws SupportException
     */
    public function reply(
        SupportTicket $ticket,
        string $body,
        AdminUser $actor,
        bool $internal = false,
        ?CannedReply $canned = null,
    ): SupportTicketMessage {
        if (trim($body) === '') {
            throw new SupportException('VALIDATION_ERROR', 'An empty reply is not a reply.', 422);
        }

        if ($ticket->status === SupportTicket::CLOSED) {
            throw new SupportException('BAD_REQUEST', 'That ticket is closed. Reopen it before replying.', 400);
        }

        return DB::transaction(function () use ($ticket, $body, $actor, $internal, $canned) {
            $message = SupportTicketMessage::create([
                'ticket_id'   => $ticket->id,
                'sender_type' => SupportTicketMessage::FROM_ADMIN,
                'sender_id'   => $actor->id,
                'body'        => trim($body),
                'is_internal' => $internal,
            ]);

            // An internal note does not stop the clock. The person waiting has not heard
            // anything, so the promise has not been kept — recording it as a response
            // would make the SLA report flattering and useless.
            if (! $internal && $ticket->first_response_at === null) {
                $ticket->forceFill(['first_response_at' => now()])->save();
            }

            // Waiting on them now, not on us.
            if (! $internal && $ticket->status === SupportTicket::OPEN) {
                $ticket->forceFill(['status' => SupportTicket::PENDING])->save();
            }

            if (! $internal && $ticket->user_id !== null) {
                $this->notifyUser($ticket, 'A reply on your support ticket', trim($body));
            }

            $canned?->recordUse();

            return $message;
        });
    }

    /**
     * @throws SupportException
     */
    public function resolve(SupportTicket $ticket, string $resolution, AdminUser $actor): SupportTicket
    {
        if (! $ticket->isOpen()) {
            throw new SupportException('BAD_REQUEST', "That ticket is already {$ticket->status}.", 400);
        }

        if (trim($resolution) === '') {
            throw new SupportException('VALIDATION_ERROR', 'A resolution note is required.', 422);
        }

        $before = ['status' => $ticket->status];

        $ticket->forceFill([
            'status'      => SupportTicket::RESOLVED,
            'resolved_at' => now(),
            'resolved_by' => $actor->id,
            'resolution'  => trim($resolution),
        ])->save();

        $this->system($ticket, 'Resolved: '.trim($resolution));

        if ($ticket->user_id !== null) {
            $this->notifyUser($ticket, 'Your support ticket is resolved', trim($resolution));
        }

        $this->audit->log($actor, 'support.resolve', 'support', SupportTicket::class, $ticket->id, $before, [
            'status' => SupportTicket::RESOLVED,
        ]);

        return $ticket->refresh();
    }

    /**
     * Escalate to an Admin — B.4c.
     *
     * @throws SupportException
     */
    public function escalate(SupportTicket $ticket, AdminUser $to, string $note, AdminUser $actor): SupportTicket
    {
        if (! $ticket->isOpen()) {
            throw new SupportException('BAD_REQUEST', "That ticket is already {$ticket->status}.", 400);
        }

        if (trim($note) === '') {
            throw new SupportException('VALIDATION_ERROR', 'An escalation needs a note saying what is stuck.', 422);
        }

        $ticket->forceFill([
            'escalated_to'    => $to->id,
            'escalated_at'    => now(),
            'escalation_note' => trim($note),
            // Escalating hands it over. Leaving it assigned to whoever gave up on it is
            // how a ticket ends up owned by nobody.
            'assigned_to'     => $to->id,
            'assigned_at'     => now(),
        ])->save();

        $this->system($ticket, "Escalated to {$to->name}: ".trim($note));

        // Both halves of B.4c: the named Admin is notified *and* it is on the ticket.
        Notification::create([
            'admin_user_id' => $to->id,
            'type'          => 'support_escalation',
            'title'         => "Ticket {$ticket->ref} escalated to you",
            'body'          => trim($note),
            'data'          => ['ticket_id' => $ticket->id, 'ref' => $ticket->ref],
            'deep_link'     => "/support/{$ticket->id}",
            'channel'       => 'in_app',
            'sent_at'       => now(),
        ]);

        $this->audit->log($actor, 'support.escalate', 'support', SupportTicket::class, $ticket->id, null, [
            'escalated_to' => $to->id, 'note' => trim($note),
        ]);

        return $ticket->refresh();
    }

    /**
     * B.4b — flag a room to the Moderator queue.
     *
     * Writes a `high` priority report naming the flagging Manager. It lands in the same
     * table the Moderator console reads, so there is no sync step and nothing to be late.
     */
    public function flagRoom(int $roomId, string $reason, AdminUser $actor): Report
    {
        $report = Report::create([
            'target_type'  => 'room',
            'target_id'    => (string) $roomId,
            'category'     => 'other',
            'description'  => sprintf('Flagged by %s (%s): %s', $actor->name, $actor->email, trim($reason)),
            // B.4b fixes the priority. A Manager flagging a live room is not routine, and
            // letting them choose would put some of these at the bottom of the queue.
            'priority'     => 'high',
            'status'       => Report::OPEN,
        ]);

        $this->audit->log($actor, 'support.flag_room', 'support', Report::class, $report->id, null, [
            'room_id' => $roomId, 'reason' => trim($reason),
        ]);

        return $report;
    }

    /**
     * Tickets past their first-response promise — what B.4c escalates.
     *
     * @return \Illuminate\Support\Collection<int, SupportTicket>
     */
    public function breaching(): \Illuminate\Support\Collection
    {
        return SupportTicket::query()->breaching()->with('assignee:id,name')->queueOrder()->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(?int $assignedTo = null): array
    {
        $open = SupportTicket::query()->open();

        $byPriority = (clone $open)
            ->selectRaw('priority, COUNT(*) AS total')
            ->groupBy('priority')
            ->pluck('total', 'priority');

        return [
            'open'      => (clone $open)->count(),
            'urgent'    => (int) ($byPriority['urgent'] ?? 0),
            'high'      => (int) ($byPriority['high'] ?? 0),
            'unassigned' => (clone $open)->whereNull('assigned_to')->count(),
            'mine'      => $assignedTo === null ? 0 : (clone $open)->where('assigned_to', $assignedTo)->count(),
            'unanswered' => (clone $open)->whereNull('first_response_at')->count(),
            // Counted in SQL against each ticket's own stored SLA, so a policy change does
            // not retroactively move the number.
            'breaching' => SupportTicket::query()->breaching()->count(),
            'escalated' => (clone $open)->whereNotNull('escalated_at')->count(),
        ];
    }

    protected function system(SupportTicket $ticket, string $body): void
    {
        SupportTicketMessage::create([
            'ticket_id'   => $ticket->id,
            'sender_type' => SupportTicketMessage::FROM_SYSTEM,
            'body'        => $body,
        ]);
    }

    protected function notifyUser(SupportTicket $ticket, string $title, string $body): void
    {
        Notification::create([
            'user_id'   => $ticket->user_id,
            'type'      => 'support_reply',
            'title'     => $title,
            'body'      => str($body)->limit(500)->value(),
            'data'      => ['ticket_id' => $ticket->id, 'ref' => $ticket->ref],
            'channel'   => 'in_app',
            'sent_at'   => now(),
        ]);
    }

    /**
     * Promise per priority. An urgent ticket is urgent because somebody is locked out of
     * their money, so it gets a much shorter clock than a bug report.
     */
    protected function firstResponseSla(string $priority): int
    {
        return match ($priority) {
            'urgent' => 30,
            'high'   => 120,
            'medium' => 240,
            default  => 480,
        };
    }

    protected function resolutionSla(string $priority): int
    {
        return match ($priority) {
            'urgent' => 240,
            'high'   => 1440,
            'medium' => 2880,
            default  => 5760,
        };
    }
}
