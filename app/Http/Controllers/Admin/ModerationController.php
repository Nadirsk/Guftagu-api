<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Moderation\BanPolicy;
use App\Domain\Moderation\ModerationService;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\ModerationLog;
use App\Models\Report;
use App\Models\ReportAction;
use App\Models\User;
use App\Models\UserSanction;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Epic A.5b/c/d and C.3–C.5 — the reports queue, sanctions and oversight. docs/03 §10.
 */
class ModerationController extends Controller
{
    public function __construct(
        protected ModerationService $moderation,
        protected BanPolicy $banPolicy,
    ) {
    }

    /**
     * POST /admin/reports/{report}/claim — C.3a.
     */
    public function claim(Request $request, Report $report): JsonResponse
    {
        $claimed = $this->moderation->claim($report, $request->user());

        return ApiResponse::success([
            'claimed_by'     => $claimed->claimedBy?->name,
            'expires_in_min' => $claimed->claimExpiresIn(),
            'note' => sprintf(
                'Yours for %d minutes. It frees up on its own after that, so a report is never stuck behind somebody who walked away.',
                Report::CLAIM_MINUTES,
            ),
        ], 'Report claimed');
    }

    public function release(Request $request, Report $report): JsonResponse
    {
        $this->moderation->release($report, $request->user());

        return ApiResponse::success(null, 'Claim released');
    }

    /** GET /admin/moderation/recurring — C.5c. */
    public function recurring(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hours'     => ['sometimes', 'integer', 'min:1', 'max:168'],
            'threshold' => ['sometimes', 'integer', 'min:2', 'max:100'],
        ]);

        return ApiResponse::success($this->moderation->recurringIssues(
            (int) ($data['hours'] ?? 24),
            (int) ($data['threshold'] ?? 5),
        ));
    }

    /** GET /admin/moderation/my-actions — GFT-174. */
    public function myActions(Request $request): JsonResponse
    {
        $days = (int) ($request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:180']])['days'] ?? 30);

        $actions = $this->moderation->ownActions($request->user(), $days);

        return ApiResponse::success([
            'days'     => $days,
            'actions'  => $actions,
            'reversed' => $actions->where('reversed', true)->count(),
            // Their own reversals included: hiding them from the person who made the call
            // would defeat the point of them having a log.
            'note' => 'Includes actions an Admin later reversed.',
        ]);
    }

    /** GET /admin/moderation/policy — what this moderator is allowed to do. */
    public function policy(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'ban'        => $this->banPolicy->describe($request->user()),
            'claim_minutes' => Report::CLAIM_MINUTES,
        ]);
    }

    /**
     * GET /admin/reports — A.5b.
     *
     * Priority order, oldest first inside a priority. Defaults to the open queue, because
     * that is what a moderator opens this screen to work through.
     */
    public function reports(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status'      => ['sometimes', 'nullable', 'string', 'max:20'],
            'priority'    => ['sometimes', 'nullable', Rule::in(Report::PRIORITIES)],
            'category'    => ['sometimes', 'nullable', Rule::in(Report::CATEGORIES)],
            'assigned_to' => ['sometimes', 'nullable', 'integer'],
            'mine'        => ['sometimes', 'boolean'],
            'page'        => ['sometimes', 'integer', 'min:1'],
            'per_page'    => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Report::query()
            ->with(['assignedTo:id,name', 'claimedBy:id,name', 'resolvedBy:id,name', 'reporter:id,guftagu_id'])
            ->when(
                array_key_exists('status', $data) && $data['status'] !== null,
                fn ($q) => $q->where('status', $data['status']),
                fn ($q) => $q->open(),
            )
            ->when($data['priority'] ?? null, fn ($q, string $p) => $q->where('priority', $p))
            ->when($data['category'] ?? null, fn ($q, string $c) => $q->where('category', $c))
            ->when($data['assigned_to'] ?? null, fn ($q, int $a) => $q->where('assigned_to', $a))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->queueOrder();

        $paginator = $query->paginate(
            perPage: (int) ($data['per_page'] ?? 25),
            page: (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (Report $report) => $this->reportPayload($report)
        )->all());
    }

    /** Queue totals, so a moderator can see the shape of the backlog before diving in. */
    public function queueSummary(Request $request): JsonResponse
    {
        $open = Report::query()->open();

        $byPriority = (clone $open)
            ->selectRaw('priority, COUNT(*) AS total')
            ->groupBy('priority')
            ->pluck('total', 'priority');

        return ApiResponse::success([
            'open'      => (clone $open)->count(),
            'critical'  => (int) ($byPriority['critical'] ?? 0),
            'high'      => (int) ($byPriority['high'] ?? 0),
            'medium'    => (int) ($byPriority['medium'] ?? 0),
            'low'       => (int) ($byPriority['low'] ?? 0),
            'unassigned' => (clone $open)->whereNull('assigned_to')->count(),
            'mine'      => (clone $open)->where('assigned_to', $request->user()->id)->count(),
            // The one a moderator should look at next.
            'oldest_critical' => (clone $open)->where('priority', 'critical')
                ->orderBy('created_at')->value('created_at'),
        ]);
    }

    /** GET /admin/reports/{report} — C.3b: evidence and the target's history together. */
    public function showReport(Request $request, Report $report): JsonResponse
    {
        $report->load(['assignedTo:id,name', 'claimedBy:id,name', 'resolvedBy:id,name', 'reporter:id,guftagu_id', 'actions.adminUser:id,name', 'actions.reversedBy:id,name']);

        $target = $report->target_type === 'user' ? User::with('profile:id,user_id,display_name')->find($report->target_id) : null;

        return ApiResponse::success([
            'report'  => $this->reportPayload($report),
            'actions' => $report->actions->map(fn (ReportAction $a) => [
                'id'               => $a->id,
                'action'           => $a->action,
                'duration_minutes' => $a->duration_minutes,
                'note'             => $a->note,
                'by'               => $a->adminUser?->name,
                'created_at'       => $a->created_at?->toIso8601ZuluString(),
                'reversed'         => $a->wasReversed(),
                'reversed_by'      => $a->reversedBy?->name,
                'reversal_reason'  => $a->reversal_reason,
            ]),
            // Deciding on a report needs to know whether this is a first offence.
            'target' => $target === null ? null : [
                'id'               => $target->id,
                'guftagu_id'       => $target->guftagu_id,
                'display_name'     => $target->profile?->display_name,
                'status'           => $target->status,
                'effective_status' => $target->effectiveStatus(),
                'prior_sanctions'  => UserSanction::where('user_id', $target->id)->count(),
                'open_reports'     => Report::where('target_type', 'user')
                    ->where('target_id', $target->id)->open()->count(),
            ],
            'target_note' => $target === null
                ? 'Room, message and post targets resolve once those modules land.'
                : null,
            // Answered here rather than left to the UI to infer from two other fields.
            'actionable_by_me' => $report->isActionableBy($request->user()->id),
            'ban_policy'       => $this->banPolicy->describe($request->user()),
        ]);
    }

    public function assign(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate([
            'admin_user_id' => ['required', 'integer', Rule::exists('admin_users', 'id')],
        ]);

        $this->moderation->assign($report, AdminUser::findOrFail($data['admin_user_id']), $request->user());

        return ApiResponse::success(null, 'Report assigned');
    }

    /** POST /admin/reports/{report}/action — C.3c. */
    public function action(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate([
            'action'           => ['required', Rule::in(ReportAction::ACTIONS)],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:525600'],
            'note'             => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $record = $this->moderation->action(
            $report,
            $data['action'],
            $data['duration_minutes'] ?? null,
            $data['note'],
            $request->user(),
        );

        return ApiResponse::success([
            'action_id' => $record->id,
            'status'    => $report->fresh()->status,
        ], 'Report actioned');
    }

    public function dismiss(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);

        $this->moderation->action($report, 'dismiss', null, $data['note'], $request->user());

        return ApiResponse::success(null, 'Report dismissed');
    }

    public function escalate(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate([
            'to_admin_id' => ['required', 'integer', Rule::exists('admin_users', 'id')],
            'note'        => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->moderation->escalate($report, AdminUser::findOrFail($data['to_admin_id']), $data['note'], $request->user());

        return ApiResponse::success(null, 'Report escalated');
    }

    /** A.5c — undo a moderator's decision. */
    public function reverse(Request $request, ReportAction $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->moderation->reverse($action, $data['reason'], $request->user());

        return ApiResponse::success(null, 'Action reversed');
    }

    // ------------------------------------------------------------- sanctions

    public function sanctions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'     => ['sometimes', 'nullable', 'integer'],
            'active_only' => ['sometimes', 'boolean'],
            'page'        => ['sometimes', 'integer', 'min:1'],
            'per_page'    => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = UserSanction::query()
            ->with(['user:id,guftagu_id', 'issuer:id,name'])
            ->when($data['user_id'] ?? null, fn ($q, int $u) => $q->where('user_id', $u))
            ->when($request->boolean('active_only'), fn ($q) => $q->active())
            ->latest('id')
            ->paginate(
                perPage: (int) ($data['per_page'] ?? 25),
                page: (int) ($data['page'] ?? 1),
            );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(fn (UserSanction $s) => [
            'id'         => $s->id,
            'user'       => $s->user === null ? null : ['id' => $s->user->id, 'guftagu_id' => $s->user->guftagu_id],
            'type'       => $s->type,
            'scope'      => $s->scope,
            'reason'     => $s->reason,
            'issued_by'  => $s->issuer?->name,
            'starts_at'  => $s->starts_at?->toIso8601ZuluString(),
            'expires_at' => $s->expires_at?->toIso8601ZuluString(),
            'revoked_at' => $s->revoked_at?->toIso8601ZuluString(),
            // The stored flag and whether it still bites are different things once a
            // window has lapsed — report both, like featured rooms.
            'is_active'  => $s->is_active,
            'in_force'   => $s->is_active && $s->revoked_at === null
                && ($s->expires_at === null || $s->expires_at->isFuture()),
        ])->all());
    }

    /** GET /admin/moderation/logs — A.5c, C.4c. */
    public function logs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'admin_user_id' => ['sometimes', 'nullable', 'integer'],
            'action'        => ['sometimes', 'nullable', 'string', 'max:60'],
            'page'          => ['sometimes', 'integer', 'min:1'],
            'per_page'      => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = ModerationLog::query()
            ->with('adminUser:id,name')
            ->when($data['admin_user_id'] ?? null, fn ($q, int $a) => $q->where('admin_user_id', $a))
            ->when($data['action'] ?? null, fn ($q, string $a) => $q->where('action', $a))
            ->latest('id')
            ->paginate(
                perPage: (int) ($data['per_page'] ?? 50),
                page: (int) ($data['page'] ?? 1),
            );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(fn (ModerationLog $log) => [
            'id'          => $log->id,
            // Null means time did it, not a person — sanction expiries land here.
            'by'          => $log->adminUser?->name ?? 'system',
            'action'      => $log->action,
            'target_type' => $log->target_type,
            'target_id'   => $log->target_id,
            'room_id'     => $log->room_id,
            'reason'      => $log->reason,
            'ip'          => $log->ip,
            'created_at'  => $log->created_at?->toIso8601ZuluString(),
        ])->all());
    }

    /** GET /admin/moderation/stats — A.5c. */
    public function stats(Request $request): JsonResponse
    {
        // The cast binds tighter than `??`, so `(int) $validated['days'] ?? 7` reads the
        // missing key first and 500s on the default path. Resolve the default first.
        $validated = $request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:90']]);
        $days = (int) ($validated['days'] ?? 7);

        return ApiResponse::success([
            'days'       => $days,
            'moderators' => $this->moderation->moderatorStats($days),
            'note'       => 'Response time is measured from when the report arrived, not from assignment — a report left unassigned for a day is still a slow response.',
        ]);
    }

    /** GET /admin/moderation/alerts — C.5a, the critical lane. */
    public function alerts(): JsonResponse
    {
        $critical = Report::query()
            ->with(['assignedTo:id,name', 'claimedBy:id,name'])
            ->open()
            ->where('priority', 'critical')
            ->queueOrder()
            ->limit(20)
            ->get();

        return ApiResponse::success([
            'count'   => $critical->count(),
            'reports' => $critical->map(fn (Report $r) => $this->reportPayload($r)),
        ]);
    }

    protected function reportPayload(Report $report): array
    {
        return [
            'id'          => $report->id,
            'uuid'        => $report->uuid,
            'target_type' => $report->target_type,
            'target_id'   => $report->target_id,
            'category'    => $report->category,
            'description' => $report->description,
            'evidence_urls' => $report->evidence_urls,
            'priority'    => $report->priority,
            'status'      => $report->status,
            'is_open'     => $report->isOpen(),
            'reporter'    => $report->reporter === null ? null : [
                'id' => $report->reporter->id, 'guftagu_id' => $report->reporter->guftagu_id,
            ],
            'assigned_to'     => $report->assignedTo?->name,
            'assigned_at'     => $report->assigned_at?->toIso8601ZuluString(),
            'resolved_by'     => $report->resolvedBy?->name,
            'resolved_at'     => $report->resolved_at?->toIso8601ZuluString(),
            'resolution_note' => $report->resolution_note,
            'created_at'      => $report->created_at?->toIso8601ZuluString(),
            // How long it has been waiting — the number a queue is actually judged on.
            'waiting_minutes' => $report->isOpen() && $report->created_at
                ? (int) $report->created_at->diffInMinutes(now())
                : null,
            // C.3a. Derived, so a lapsed claim reads as free without anything having run.
            'claimed_by'      => $report->isClaimed() ? $report->claimedBy?->name : null,
            'claim_expires_in_min' => $report->claimExpiresIn(),
        ];
    }
}
