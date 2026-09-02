<?php

namespace App\Domain\Moderation;

use App\Domain\Audit\AuditLogger;
use App\Domain\Users\SanctionService;
use App\Models\AdminUser;
use App\Models\ModerationLog;
use App\Models\Report;
use App\Models\ReportAction;
use App\Models\User;
use App\Models\UserSanction;
use Illuminate\Support\Facades\DB;

/**
 * GFT-049 / GFT-050 / GFT-051 — the reports queue and the sanctions that come out of it
 * (A.5b, A.5c, A.5d; C.3, C.4).
 *
 * Reuses `SanctionService` from A.3 rather than growing a second way to ban someone —
 * two code paths that both set `users.status` is how they drift apart.
 */
class ModerationService
{
    public function __construct(
        protected SanctionService $sanctions,
        protected AuditLogger $audit,
        protected BanPolicy $banPolicy,
    ) {
    }

    // ------------------------------------------------------------ claims (C.3a)

    /**
     * Claim a report so nobody else acts on it.
     *
     * A claim is not an assignment: `assigned_to` is a supervisor saying "you handle
     * this", a claim is a moderator saying "I am reading this now". It is what stops two
     * people issuing two bans off the same report.
     *
     * @throws ModerationException
     */
    public function claim(Report $report, AdminUser $actor): Report
    {
        if (! $report->isOpen()) {
            throw new ModerationException('BAD_REQUEST', "That report is already {$report->status}.", 400);
        }

        // Locked inside a transaction: two moderators clicking at the same moment must
        // not both come away believing they hold it.
        return DB::transaction(function () use ($report, $actor) {
            $fresh = Report::whereKey($report->id)->lockForUpdate()->firstOrFail();

            if ($fresh->isClaimed() && $fresh->claimed_by !== $actor->id) {
                throw new ModerationException(
                    'ALREADY_CLAIMED',
                    sprintf(
                        '%s is reviewing this report. It frees up in %d minutes if they do not act.',
                        $fresh->claimedBy?->name ?? 'Another moderator',
                        $fresh->claimExpiresIn() ?? 0,
                    ),
                    409,
                );
            }

            $fresh->forceFill(['claimed_by' => $actor->id, 'claimed_at' => now()])->save();

            return $fresh;
        });
    }

    /**
     * Release a claim.
     *
     * Only the holder may release, so one moderator cannot bump another off a report they
     * are mid-way through. A stale claim needs no release — it lapses on its own.
     *
     * @throws ModerationException
     */
    public function release(Report $report, AdminUser $actor): Report
    {
        if ($report->isClaimed() && $report->claimed_by !== $actor->id) {
            throw new ModerationException(
                'NOT_YOURS',
                'That claim belongs to somebody else. It will lapse on its own.',
                403,
            );
        }

        $report->forceFill(['claimed_by' => null, 'claimed_at' => null])->save();

        return $report->refresh();
    }

    /**
     * @throws ModerationException
     */
    protected function guardClaim(Report $report, AdminUser $actor): void
    {
        if ($report->isActionableBy($actor->id)) {
            return;
        }

        throw new ModerationException(
            'ALREADY_CLAIMED',
            sprintf(
                'C.3a: %s has this report claimed for another %d minutes. Ask them to release it, or wait.',
                $report->claimedBy?->name ?? 'Another moderator',
                $report->claimExpiresIn() ?? 0,
            ),
            409,
        );
    }

    /**
     * @throws ModerationException
     */
    public function assign(Report $report, AdminUser $assignee, AdminUser $actor): Report
    {
        if (! $report->isOpen()) {
            throw new ModerationException('BAD_REQUEST', "That report is already {$report->status}.", 400);
        }

        $before = ['assigned_to' => $report->assigned_to, 'status' => $report->status];

        $report->forceFill([
            'assigned_to' => $assignee->id,
            'assigned_at' => now(),
            'status'      => Report::ASSIGNED,
        ])->save();

        $this->audit->log($actor, 'report.assign', 'moderation', Report::class, $report->id, $before, [
            'assigned_to' => $assignee->id,
        ]);

        return $report->refresh();
    }

    /**
     * Action a report — the moderator's actual decision (C.3c).
     *
     * The sanction and the report resolution happen together: a report marked actioned
     * with no sanction behind it, or a ban with no report explaining it, are both
     * states somebody has to untangle later.
     *
     * @throws ModerationException
     */
    public function action(Report $report, string $action, ?int $durationMinutes, string $note, AdminUser $actor): ReportAction
    {
        if (! $report->isOpen()) {
            throw new ModerationException('BAD_REQUEST', "That report is already {$report->status}.", 400);
        }

        if (! in_array($action, ReportAction::ACTIONS, true)) {
            throw new ModerationException('VALIDATION_ERROR', 'That is not a moderation action.', 422);
        }

        if (trim($note) === '') {
            throw new ModerationException('VALIDATION_ERROR', 'A note is required — every action has to be explicable.', 422);
        }

        // C.3a — somebody else is holding this one.
        $this->guardClaim($report, $actor);

        // C.4b — a moderator with a 72-hour ceiling cannot issue 30 days. Checked before
        // anything is written, so a refused ban leaves no half-applied sanction behind.
        if ($action === 'ban_temp') {
            $this->banPolicy->guardDuration($actor, $durationMinutes ?? 1440);
        }

        $target = $report->target_type === 'user' ? User::find($report->target_id) : null;

        $record = DB::transaction(function () use ($report, $action, $durationMinutes, $note, $actor, $target) {
            $record = ReportAction::create([
                'report_id'        => $report->id,
                'admin_user_id'    => $actor->id,
                'action'           => $action,
                'duration_minutes' => $durationMinutes,
                'note'             => trim($note),
            ]);

            // Sanctions that actually change a user's state go through the A.3 service.
            if ($target !== null) {
                match ($action) {
                    'ban_permanent' => $this->sanctions->ban($target, $note, $actor),
                    'ban_temp'      => $this->sanctions->suspend(
                        $target,
                        $note,
                        now()->addMinutes($durationMinutes ?? 1440)->toIso8601String(),
                        $actor,
                    ),
                    // warn / mute / kick are recorded but do not lock the account. Mute and
                    // kick are room-scoped and need the realtime layer to take effect.
                    'warn', 'mute', 'kick' => UserSanction::create([
                        'user_id'    => $target->id,
                        'type'       => $action === 'warn' ? UserSanction::WARNING : $action,
                        'scope'      => $action === 'warn' ? 'global' : 'room',
                        'reason'     => trim($note),
                        'report_id'  => $report->id,
                        'issued_by'  => $actor->id,
                        'starts_at'  => now(),
                        'expires_at' => $durationMinutes === null ? null : now()->addMinutes($durationMinutes),
                        'is_active'  => true,
                    ]),
                    default => null,
                };
            }

            $report->forceFill([
                'status'          => $action === 'dismiss' ? Report::DISMISSED : Report::ACTIONED,
                'resolved_by'     => $actor->id,
                'resolved_at'     => now(),
                'resolution_note' => trim($note),
                // A resolved report needs no claim, and leaving one on it would make the
                // queue look busier than it is.
                'claimed_by'      => null,
                'claimed_at'      => null,
            ])->save();

            return $record;
        });

        ModerationLog::create([
            'admin_user_id' => $actor->id,
            'action'        => $action,
            'target_type'   => $report->target_type,
            'target_id'     => (string) $report->target_id,
            'after'         => ['report_id' => $report->id, 'duration_minutes' => $durationMinutes],
            'reason'        => trim($note),
            'ip'            => request()->ip(),
        ]);

        $this->audit->log($actor, 'report.action', 'moderation', Report::class, $report->id, null, [
            'action' => $action, 'note' => trim($note),
        ]);

        return $record;
    }

    /**
     * @throws ModerationException
     */
    public function escalate(Report $report, AdminUser $to, string $note, AdminUser $actor): Report
    {
        if (! $report->isOpen()) {
            throw new ModerationException('BAD_REQUEST', "That report is already {$report->status}.", 400);
        }

        $this->guardClaim($report, $actor);

        $report->forceFill([
            'status'        => Report::ESCALATED,
            'escalated_to'  => $to->id,
            'escalated_at'  => now(),
            'assigned_to'   => $to->id,
            'resolution_note' => trim($note),
            // Handed on, so the claim goes with it rather than blocking the recipient.
            'claimed_by'    => null,
            'claimed_at'    => null,
        ])->save();

        ReportAction::create([
            'report_id'     => $report->id,
            'admin_user_id' => $actor->id,
            'action'        => 'escalate',
            'note'          => trim($note),
        ]);

        $this->audit->log($actor, 'report.escalate', 'moderation', Report::class, $report->id, null, [
            'escalated_to' => $to->id, 'note' => trim($note),
        ]);

        return $report->refresh();
    }

    /**
     * A.5c — undo a moderator's action.
     *
     * The reversal is written onto the original action rather than as a new independent
     * row, because the oversight view needs to know *which* action was wrong, not merely
     * that a reversal happened.
     *
     * @throws ModerationException
     */
    public function reverse(ReportAction $action, string $reason, AdminUser $actor): ReportAction
    {
        if ($action->wasReversed()) {
            throw new ModerationException('BAD_REQUEST', 'That action has already been reversed.', 400);
        }

        if (trim($reason) === '') {
            throw new ModerationException('VALIDATION_ERROR', 'A reason is required to reverse an action.', 422);
        }

        DB::transaction(function () use ($action, $reason, $actor) {
            $action->forceFill([
                'reversed_by'     => $actor->id,
                'reversed_at'     => now(),
                'reversal_reason' => trim($reason),
            ])->save();

            // Undoing a ban has to actually let the person back in, or the reversal is
            // paperwork.
            if (in_array($action->action, ['ban_temp', 'ban_permanent'], true)) {
                $report = $action->report;
                $target = $report?->target_type === 'user' ? User::find($report->target_id) : null;

                if ($target !== null && $target->status !== User::STATUS_ACTIVE) {
                    $this->sanctions->unban($target, "Reversed: {$reason}", $actor);
                }
            }
        });

        ModerationLog::create([
            'admin_user_id' => $actor->id,
            'action'        => 'reverse',
            'target_type'   => ReportAction::class,
            'target_id'     => (string) $action->id,
            'before'        => ['action' => $action->action, 'by' => $action->admin_user_id],
            'reason'        => trim($reason),
            'ip'            => request()->ip(),
        ]);

        return $action->refresh();
    }

    /**
     * A.5c — per-moderator oversight: how much they did, how fast, and how often an Admin
     * had to undo it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function moderatorStats(int $days = 7): array
    {
        $since = now()->subDays($days);

        $rows = DB::table('report_actions')
            ->join('admin_users', 'admin_users.id', '=', 'report_actions.admin_user_id')
            ->join('reports', 'reports.id', '=', 'report_actions.report_id')
            ->where('report_actions.created_at', '>=', $since)
            ->groupBy('admin_users.id', 'admin_users.name')
            ->selectRaw('admin_users.id, admin_users.name')
            ->selectRaw('COUNT(*) AS actions')
            ->selectRaw('SUM(CASE WHEN report_actions.reversed_at IS NOT NULL THEN 1 ELSE 0 END) AS reversed')
            ->selectRaw('SUM(CASE WHEN report_actions.action = "dismiss" THEN 1 ELSE 0 END) AS dismissed')
            // Response time is measured from when the report arrived, not from assignment
            // — a report sitting unassigned for a day is still a slow response.
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, reports.created_at, report_actions.created_at)) AS avg_minutes')
            ->orderByDesc('actions')
            ->get();

        return $rows->map(function ($row) {
            $actions = (int) $row->actions;
            $reversed = (int) $row->reversed;

            return [
                'admin_user_id'        => (int) $row->id,
                'name'                 => $row->name,
                'actions'              => $actions,
                'dismissed'            => (int) $row->dismissed,
                'reversed'             => $reversed,
                // The number an oversight view is actually for.
                'reversal_rate'        => $actions > 0 ? round($reversed / $actions, 4) : 0.0,
                'avg_response_minutes' => $row->avg_minutes === null ? null : (int) round($row->avg_minutes),
            ];
        })->all();
    }

    /**
     * C.5c — "a user reported 5+ times in 24 hours surfaces in the recurring-issues panel".
     *
     * Derived on read. A `repeat_offender` flag written by a job would be wrong the moment
     * the window rolled forward, and the whole point of the panel is that it reflects the
     * last 24 hours right now.
     *
     * @return array<string, mixed>
     */
    public function recurringIssues(int $hours = 24, int $threshold = 5): array
    {
        $since = now()->subHours($hours);

        $users = DB::table('reports')
            ->join('users', 'users.id', '=', DB::raw('CAST(reports.target_id AS UNSIGNED)'))
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->where('reports.target_type', 'user')
            ->where('reports.created_at', '>=', $since)
            ->groupBy('users.id', 'users.guftagu_id', 'user_profiles.display_name', 'users.status')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->selectRaw('users.id, users.guftagu_id, user_profiles.display_name, users.status')
            ->selectRaw('COUNT(*) AS reports')
            ->selectRaw('COUNT(DISTINCT reports.reporter_id) AS distinct_reporters')
            ->selectRaw('MAX(reports.priority = \'critical\') AS has_critical')
            ->orderByDesc('reports')
            ->limit(50)
            ->get();

        $rooms = DB::table('reports')
            ->where('target_type', 'room')
            ->where('created_at', '>=', $since)
            ->groupBy('target_id')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->selectRaw('target_id, COUNT(*) AS reports')
            ->orderByDesc('reports')
            ->limit(50)
            ->get();

        return [
            'window_hours' => $hours,
            'threshold'    => $threshold,
            'users' => $users->map(fn ($row) => [
                'id'                 => (int) $row->id,
                'guftagu_id'         => $row->guftagu_id,
                'display_name'       => $row->display_name,
                'status'             => $row->status,
                'reports'            => (int) $row->reports,
                // Five reports from one person is a feud; five from five people is a
                // pattern. The distinction changes what a moderator should do about it.
                'distinct_reporters' => (int) $row->distinct_reporters,
                'has_critical'       => (bool) $row->has_critical,
            ]),
            'rooms' => $rooms->map(fn ($row) => [
                'room_id' => $row->target_id,
                'reports' => (int) $row->reports,
            ]),
            'note' => 'Counted over a rolling window at read time, so this is the last '.$hours.' hours as of now.',
        ];
    }

    /**
     * GFT-174 — a moderator's own actions.
     *
     * Their own, including the ones an Admin later reversed. Hiding a reversal from the
     * person who made the call would defeat the point of them having a log.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function ownActions(AdminUser $actor, int $days = 30): \Illuminate\Support\Collection
    {
        return ReportAction::query()
            ->with(['report:id,category,target_type,target_id', 'reversedBy:id,name'])
            ->where('admin_user_id', $actor->id)
            ->where('created_at', '>=', now()->subDays($days))
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (ReportAction $a) => [
                'id'               => $a->id,
                'report_id'        => $a->report_id,
                'category'         => $a->report?->category,
                'target'           => $a->report === null ? null : $a->report->target_type.' #'.$a->report->target_id,
                'action'           => $a->action,
                'duration_minutes' => $a->duration_minutes,
                'note'             => $a->note,
                'created_at'       => $a->created_at?->toIso8601ZuluString(),
                'reversed'         => $a->wasReversed(),
                'reversed_by'      => $a->reversedBy?->name,
                'reversal_reason'  => $a->reversal_reason,
            ]);
    }
}
