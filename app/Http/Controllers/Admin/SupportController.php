<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Support\SupportService;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\CannedReply;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GFT-142 – GFT-146 — the support inbox (epic B.4). docs/03 §16.
 */
class SupportController extends Controller
{
    public function __construct(protected SupportService $support)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status'    => ['sometimes', 'nullable', Rule::in(SupportTicket::STATUSES)],
            'priority'  => ['sometimes', 'nullable', Rule::in(SupportTicket::PRIORITIES)],
            'category'  => ['sometimes', 'nullable', Rule::in(SupportTicket::CATEGORIES)],
            'sla'       => ['sometimes', 'nullable', Rule::in(['breaching', 'unanswered', 'escalated'])],
            'mine'      => ['sometimes', 'boolean'],
            'q'         => ['sometimes', 'nullable', 'string', 'max:100'],
            'page'      => ['sometimes', 'integer', 'min:1'],
            'per_page'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SupportTicket::query()
            ->with(['user:id,guftagu_id', 'user.profile:id,user_id,display_name', 'assignee:id,name', 'escalatedTo:id,name'])
            ->when(
                array_key_exists('status', $data) && $data['status'] !== null,
                fn ($q) => $q->where('status', $data['status']),
                fn ($q) => $q->open(),
            )
            ->when($data['priority'] ?? null, fn ($q, string $p) => $q->where('priority', $p))
            ->when($data['category'] ?? null, fn ($q, string $c) => $q->where('category', $c))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when($data['q'] ?? null, fn ($q, string $t) => $q->where(fn ($w) => $w
                ->where('ref', $t)
                ->orWhere('subject', 'like', "%{$t}%")))
            // The SLA filters are the ones a support lead actually uses: what is late,
            // what nobody has answered, and what has been handed upwards.
            ->when(($data['sla'] ?? null) === 'breaching', fn ($q) => $q->breaching())
            ->when(($data['sla'] ?? null) === 'unanswered', fn ($q) => $q->whereNull('first_response_at'))
            ->when(($data['sla'] ?? null) === 'escalated', fn ($q) => $q->whereNotNull('escalated_at'))
            ->queueOrder();

        $paginator = $query->paginate(
            perPage: (int) ($data['per_page'] ?? 25),
            page: (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (SupportTicket $t) => $this->rowPayload($t)
        )->all());
    }

    public function summary(Request $request): JsonResponse
    {
        return ApiResponse::success($this->support->summary($request->user()->id));
    }

    /** GET /admin/support/{ticket} — the conversation view. */
    public function show(SupportTicket $ticket): JsonResponse
    {
        $ticket->load([
            'user:id,guftagu_id', 'user.profile:id,user_id,display_name,avatar_url',
            'assignee:id,name', 'escalatedTo:id,name', 'resolver:id,name', 'messages',
        ]);

        return ApiResponse::success([
            'ticket' => $this->rowPayload($ticket) + [
                'description'     => $ticket->description,
                'attachments'     => $ticket->attachments ?? [],
                'resolution'      => $ticket->resolution,
                'resolved_by'     => $ticket->resolver?->name,
                'escalation_note' => $ticket->escalation_note,
                'escalated_to'    => $ticket->escalatedTo?->name,
            ],
            'messages' => $ticket->messages->map(fn (SupportTicketMessage $m) => [
                'id'          => $m->id,
                'sender_type' => $m->sender_type,
                'sender'      => $this->senderName($m),
                'body'        => $m->body,
                'attachments' => $m->attachments ?? [],
                // Flagged clearly: an internal note rendered like a reply is how a private
                // remark ends up quoted back at the customer.
                'is_internal' => $m->is_internal,
                'created_at'  => $m->created_at?->toIso8601ZuluString(),
            ]),
            'sla' => [
                'state'                  => $ticket->slaState(),
                'first_response_minutes' => $ticket->firstResponseMinutes(),
                'first_response_due_in'  => $ticket->firstResponseDueIn(),
                'promise_minutes'        => $ticket->sla_first_response_minutes,
                // The promise is the one that applied when the ticket was raised, not
                // today's policy.
                'note' => 'Measured against the promise in force when this ticket was raised.',
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'     => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'category'    => ['sometimes', Rule::in(SupportTicket::CATEGORIES)],
            'subject'     => ['required', 'string', 'min:3', 'max:200'],
            'description' => ['required', 'string', 'min:3', 'max:5000'],
            'priority'    => ['sometimes', Rule::in(SupportTicket::PRIORITIES)],
        ]);

        $ticket = $this->support->open(
            $data,
            isset($data['user_id']) && $data['user_id'] !== null ? User::find($data['user_id']) : null,
            $request->user(),
        );

        return ApiResponse::success($this->rowPayload($ticket), 'Ticket opened', 201);
    }

    public function assign(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'admin_user_id' => ['required', 'integer', Rule::exists('admin_users', 'id')],
        ]);

        $this->support->assign($ticket, AdminUser::findOrFail($data['admin_user_id']), $request->user());

        return ApiResponse::success(null, 'Ticket assigned');
    }

    /** POST /admin/support/{ticket}/reply — B.4a. */
    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'body'            => ['required', 'string', 'min:1', 'max:5000'],
            'is_internal'     => ['sometimes', 'boolean'],
            'canned_reply_id' => ['sometimes', 'nullable', 'integer', Rule::exists('canned_replies', 'id')],
        ]);

        $canned = isset($data['canned_reply_id']) && $data['canned_reply_id'] !== null
            ? CannedReply::find($data['canned_reply_id'])
            : null;

        $internal = (bool) ($data['is_internal'] ?? false);

        $this->support->reply($ticket, $data['body'], $request->user(), $internal, $canned);

        $fresh = $ticket->fresh();

        return ApiResponse::success([
            'status'                 => $fresh->status,
            'first_response_minutes' => $fresh->firstResponseMinutes(),
            'sla_state'              => $fresh->slaState(),
            // Said out loud, because it is the surprising half of the rule.
            'note' => $internal
                ? 'Internal note saved. The first-response clock is still running — the person waiting has not heard anything.'
                : null,
        ], $internal ? 'Note saved' : 'Reply sent');
    }

    public function resolve(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'resolution' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $this->support->resolve($ticket, $data['resolution'], $request->user());

        return ApiResponse::success(['status' => $ticket->fresh()->status], 'Ticket resolved');
    }

    /** POST /admin/support/{ticket}/escalate — B.4c. */
    public function escalate(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'admin_user_id' => ['required', 'integer', Rule::exists('admin_users', 'id')],
            'note'          => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->support->escalate(
            $ticket,
            AdminUser::findOrFail($data['admin_user_id']),
            $data['note'],
            $request->user(),
        );

        return ApiResponse::success([
            'escalated_to' => $ticket->fresh()->escalatedTo?->name,
            'note'         => 'They have been notified in-panel, and the escalation is recorded on the ticket.',
        ], 'Ticket escalated');
    }

    /** GET /admin/support/breaching — what is late. */
    public function breaching(): JsonResponse
    {
        $rows = $this->support->breaching();

        return ApiResponse::success([
            'count'   => $rows->count(),
            'tickets' => $rows->map(fn (SupportTicket $t) => $this->rowPayload($t)),
            'note' => 'Past the first-response promise and still unanswered. Each is measured against its own stored SLA.',
        ]);
    }

    /** POST /admin/support/flag-room — B.4b. */
    public function flagRoom(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer'],
            'reason'  => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $report = $this->support->flagRoom((int) $data['room_id'], $data['reason'], $request->user());

        return ApiResponse::success([
            'report_id' => $report->id,
            'priority'  => $report->priority,
            'note' => 'It is in the Moderator queue now — the same table they read, so there is nothing to wait for.',
        ], 'Room flagged to moderation', 201);
    }

    // --------------------------------------------------------- canned replies

    public function cannedReplies(Request $request): JsonResponse
    {
        $rows = CannedReply::query()
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->active()
            ->orderByDesc('use_count')
            ->get();

        return ApiResponse::success($rows->map(fn (CannedReply $r) => [
            'id'        => $r->id,
            'title'     => $r->title,
            'category'  => $r->category,
            'body_en'   => $r->body_en,
            'body_hi'   => $r->body_hi,
            'use_count' => $r->use_count,
            'bilingual' => filled($r->body_hi),
        ]));
    }

    public function storeCannedReply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'    => ['required', 'string', 'max:120'],
            'category' => ['sometimes', 'string', 'max:40'],
            'body_en'  => ['required', 'string', 'max:5000'],
            'body_hi'  => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $reply = CannedReply::create($data + ['created_by' => $request->user()->id]);

        return ApiResponse::success(['id' => $reply->id], 'Saved reply created', 201);
    }

    public function destroyCannedReply(CannedReply $cannedReply): JsonResponse
    {
        $cannedReply->delete();

        return ApiResponse::success(null, 'Saved reply removed');
    }

    // ----------------------------------------------------------------- helpers

    protected function senderName(SupportTicketMessage $message): ?string
    {
        return match ($message->sender_type) {
            SupportTicketMessage::FROM_ADMIN => AdminUser::find($message->sender_id)?->name,
            SupportTicketMessage::FROM_USER  => User::find($message->sender_id)?->guftagu_id,
            default                          => null,
        };
    }

    protected function rowPayload(SupportTicket $ticket): array
    {
        return [
            'id'        => $ticket->id,
            'ref'       => $ticket->ref,
            'subject'   => $ticket->subject,
            'category'  => $ticket->category,
            'priority'  => $ticket->priority,
            'status'    => $ticket->status,
            'is_open'   => $ticket->isOpen(),
            'user'      => $ticket->user === null ? null : [
                'id'           => $ticket->user->id,
                'guftagu_id'   => $ticket->user->guftagu_id,
                'display_name' => $ticket->user->profile?->display_name ?? null,
            ],
            'assigned_to' => $ticket->assignee?->name,
            'escalated'   => $ticket->escalated_at !== null,
            // One word for the whole SLA position — derived from the clock, so a ticket
            // crosses into breach without anything having to run.
            'sla_state'   => $ticket->slaState(),
            'first_response_due_in' => $ticket->firstResponseDueIn(),
            'first_response_minutes' => $ticket->firstResponseMinutes(),
            'created_at'  => $ticket->created_at?->toIso8601ZuluString(),
            'resolved_at' => $ticket->resolved_at?->toIso8601ZuluString(),
        ];
    }
}
