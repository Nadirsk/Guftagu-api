<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Services\ScopeFilter;
use App\Domain\Agency\AgencyService;
use App\Domain\Agency\HostEarningsRollup;
use App\Domain\Agency\TargetService;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Host;
use App\Models\HostApplication;
use App\Models\HostEarning;
use App\Models\HostTarget;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * GFT-081 / GFT-082 / GFT-084 — hosts, their applications, targets and earnings (A.8a–c).
 */
class HostController extends Controller
{
    public function __construct(
        protected AgencyService $agencies,
        protected TargetService $targets,
        protected HostEarningsRollup $rollup,
        protected ScopeFilter $scope,
    ) {
    }

    // ------------------------------------------------------------ applications

    public function applications(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status'    => ['sometimes', 'nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'agency_id' => ['sometimes', 'nullable', 'integer'],
            'page'      => ['sometimes', 'integer', 'min:1'],
            'per_page'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = HostApplication::query()
            ->with(['user:id,guftagu_id', 'user.profile:id,user_id,display_name,avatar_url', 'agency:id,name,code', 'reviewer:id,name'])
            ->when(
                array_key_exists('status', $data) && $data['status'] !== null,
                fn ($q) => $q->where('status', $data['status']),
                fn ($q) => $q->pending(),
            )
            ->when($data['agency_id'] ?? null, fn ($q, int $a) => $q->where('agency_id', $a))
            ->orderBy('created_at');

        // An application naming an agency outside the scope is not this admin's to review.
        $this->scope->applyAgency($query, $request->user(), 'host_applications.agency_id');

        $paginator = $query->paginate(
            perPage: (int) ($data['per_page'] ?? 25),
            page: (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(fn (HostApplication $a) => [
            'id'              => $a->id,
            'user'            => $a->user === null ? null : [
                'id'           => $a->user->id,
                'guftagu_id'   => $a->user->guftagu_id,
                'display_name' => $a->user->profile?->display_name,
                'avatar_url'   => $a->user->profile?->avatar_url,
            ],
            'agency'          => $a->agency === null ? null : ['id' => $a->agency->id, 'name' => $a->agency->name],
            'intro_audio_url' => $a->intro_audio_url,
            'experience'      => $a->experience,
            'status'          => $a->status,
            'reviewed_by'     => $a->reviewer?->name,
            'reviewed_at'     => $a->reviewed_at?->toIso8601ZuluString(),
            'reason'          => $a->reason,
            'created_at'      => $a->created_at?->toIso8601ZuluString(),
            'waiting_days'    => $a->isPending() && $a->created_at
                ? (int) $a->created_at->diffInDays(now())
                : null,
        ])->all());
    }

    public function approveApplication(Request $request, HostApplication $application): JsonResponse
    {
        $data = $request->validate([
            'agency_id' => ['sometimes', 'nullable', 'integer', Rule::exists('agencies', 'id')],
        ]);

        $agency = array_key_exists('agency_id', $data) && $data['agency_id'] !== null
            ? Agency::findOrFail($data['agency_id'])
            : null;

        $this->scope->guardAgency($request->user(), $application->agency_id, 'application');
        $this->scope->guardAgency($request->user(), $agency?->id ?? $application->agency_id, 'agency');

        $host = $this->agencies->approveApplication($application, $agency, $request->user());

        return ApiResponse::success(['host_id' => $host->id], 'Host approved');
    }

    public function rejectApplication(Request $request, HostApplication $application): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $application->agency_id, 'application');

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->agencies->rejectApplication($application, $data['reason'], $request->user());

        return ApiResponse::success(null, 'Application rejected');
    }

    // -------------------------------------------------------------------- hosts

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'         => ['sometimes', 'nullable', 'string', 'max:100'],
            'status'    => ['sometimes', 'nullable', Rule::in(Host::STATUSES)],
            'agency_id' => ['sometimes', 'nullable', 'integer'],
            'unassigned' => ['sometimes', 'boolean'],
            'page'      => ['sometimes', 'integer', 'min:1'],
            'per_page'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Host::query()
            ->with(['user:id,guftagu_id', 'user.profile:id,user_id,display_name,avatar_url', 'agency:id,name,code'])
            ->when($data['status'] ?? null, fn ($q, string $s) => $q->where('status', $s))
            ->when($data['agency_id'] ?? null, fn ($q, int $a) => $q->where('agency_id', $a))
            ->when($request->boolean('unassigned'), fn ($q) => $q->whereNull('agency_id'))
            ->when($data['q'] ?? null, fn ($q, string $t) => $q->whereHas('user', fn ($u) => $u->search($t)))
            ->orderByDesc('id');

        // B.1a / B.5a. A host with no agency belongs to nobody, so a scoped admin does not
        // see them either — `includeUnassigned` stays false deliberately.
        $this->scope->applyAgency($query, $request->user(), 'hosts.agency_id');

        $paginator = $query->paginate(
            perPage: (int) ($data['per_page'] ?? 25),
            page: (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (Host $h) => $this->rowPayload($h)
        )->all());
    }

    /** GET /admin/hosts/{host} — the detail A.8c is judged on. */
    public function show(Request $request, Host $host): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to'   => ['sometimes', 'date'],
        ]);

        $this->scope->guardAgency($request->user(), $host->agency_id, 'host');

        [$from, $to] = $this->range($data);

        $host->load(['user:id,guftagu_id', 'user.profile:id,user_id,display_name,avatar_url', 'agency:id,name,code', 'approver:id,name']);

        $daily = HostEarning::query()
            ->where('host_id', $host->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get()
            ->map(fn (HostEarning $e) => [
                'date'               => $e->date->toDateString(),
                'diamonds_earned'    => $e->diamonds_earned,
                'gross_paise'        => $e->gross_paise,
                'platform_cut_paise' => $e->platform_cut_paise,
                'agency_cut_paise'   => $e->agency_cut_paise,
                'net_paise'          => $e->net_paise,
                'gift_count'         => $e->gift_count,
                'room_hours'         => $e->room_hours,
                'unique_gifters'     => $e->unique_gifters,
            ]);

        $totals = $this->rollup->totals($host, $from, $to);

        return ApiResponse::success([
            'host'    => $this->rowPayload($host) + [
                'contract_start' => $host->contract_start?->toDateString(),
                'contract_end'   => $host->contract_end?->toDateString(),
                'notes'          => $host->notes,
                'approved_by'    => $host->approver?->name,
                'approved_at'    => $host->approved_at?->toIso8601ZuluString(),
            ],
            'period'  => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals'  => $totals,
            'daily'   => $daily,
            'targets' => $host->targets()->limit(12)->get()->map(fn (HostTarget $t) => $this->targetPayload($t)),
            'note'    => 'Unique gifters is null, not zero: the diamond ledger does not record who sent a gift, so it stays uncountable until gift_transactions lands with D.1.',
            // A zero rupee figure against real diamonds is a missing rate, not a quiet
            // month. Saying which one it is here saves an operator a support ticket.
            'pricing_note' => $totals['unpriced']
                ? 'These diamonds could not be converted: no diamond-to-INR rate covers this period. Set one effective from before it, then rebuild with `hosts:rollup-earnings --from --to`.'
                : null,
        ]);
    }

    /**
     * GET /admin/hosts/{host}/earnings/verify — A.8c, stated as a check rather than a claim.
     *
     * Re-derives the range straight from the diamond ledger and reports the difference.
     */
    public function verifyEarnings(Request $request, Host $host): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $host->agency_id, 'host');

        [$from, $to] = $this->range($request->validate([
            'from' => ['sometimes', 'date'],
            'to'   => ['sometimes', 'date'],
        ]));

        $result = $this->rollup->verify($host, $from, $to);

        return ApiResponse::success($result + [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'note'   => $result['matches']
                ? 'The rollup equals the ledger for this range.'
                : 'The rollup and the ledger disagree. Rebuild with `php artisan hosts:rollup-earnings --from --to`.',
        ]);
    }

    public function update(Request $request, Host $host): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $host->agency_id, 'host');

        $data = $request->validate([
            'tier'               => ['sometimes', 'nullable', 'string', 'max:20'],
            'base_commission_bp' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'contract_start'     => ['sometimes', 'nullable', 'date'],
            'contract_end'       => ['sometimes', 'nullable', 'date', 'after_or_equal:contract_start'],
            'notes'              => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $host->fill($data)->save();

        return ApiResponse::success($this->rowPayload($host->refresh()), 'Host updated');
    }

    public function assign(Request $request, Host $host): JsonResponse
    {
        $data = $request->validate([
            'agency_id' => ['present', 'nullable', 'integer', Rule::exists('agencies', 'id')],
        ]);

        $agency = $data['agency_id'] === null ? null : Agency::findOrFail($data['agency_id']);

        // Both ends of the move are checked. Guarding only the destination would let a
        // scoped admin pull somebody else's host in; guarding only the source would let
        // them push one out of reach.
        $this->scope->guardAgency($request->user(), $host->agency_id, 'host');
        $this->scope->guardAgency($request->user(), $agency?->id, 'agency');

        $this->agencies->assignToAgency($host, $agency, $request->user());

        return ApiResponse::success([
            'agency_id' => $host->fresh()->agency_id,
            // The old membership is closed rather than edited, so past settlements keep
            // pointing at the agency the host actually belonged to then.
            'note' => 'Earnings already rolled up stay attributed to the previous agency.',
        ], 'Host reassigned');
    }

    public function setStatus(Request $request, Host $host): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $host->agency_id, 'host');

        $data = $request->validate([
            'status' => ['required', Rule::in(Host::STATUSES)],
            'note'   => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $this->agencies->setHostStatus($host, $data['status'], $data['note'] ?? null, $request->user());

        return ApiResponse::success(['status' => $host->fresh()->status], 'Host status updated');
    }

    // ------------------------------------------------------------------ targets

    public function targets(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host_id'   => ['sometimes', 'nullable', 'integer'],
            'agency_id' => ['sometimes', 'nullable', 'integer'],
            'status'    => ['sometimes', 'nullable', 'string', 'max:20'],
            'page'      => ['sometimes', 'integer', 'min:1'],
            'per_page'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = HostTarget::query()
            ->with(['host.user:id,guftagu_id', 'host.agency:id,name'])
            ->when($data['host_id'] ?? null, fn ($q, int $h) => $q->where('host_id', $h))
            ->when($data['status'] ?? null, fn ($q, string $s) => $q->where('status', $s))
            ->when($data['agency_id'] ?? null, fn ($q, int $a) => $q->whereHas('host', fn ($h) => $h->where('agency_id', $a)))
            ->orderByDesc('period_start');

        $agencies = $this->scope->agencyIds($request->user());

        if ($agencies !== null) {
            // Reached through the host, so the constraint has to travel with the relation.
            $query->whereHas('host', fn ($h) => $h->whereIn('agency_id', $agencies ?: [0]));
        }

        $paginator = $query->paginate(
            perPage: (int) ($data['per_page'] ?? 25),
            page: (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (HostTarget $t) => $this->targetPayload($t)
        )->all());
    }

    public function storeTarget(Request $request, Host $host): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $host->agency_id, 'host');

        $data = $request->validate([
            'period_start'    => ['required', 'date'],
            'period_end'      => ['required', 'date', 'after_or_equal:period_start'],
            'target_diamonds' => ['sometimes', 'integer', 'min:0'],
            'target_hours'    => ['sometimes', 'integer', 'min:0'],
            'target_days'     => ['sometimes', 'integer', 'min:0', 'max:366'],
        ]);

        $target = $this->targets->create($host, $data, $request->user());

        return ApiResponse::success($this->targetPayload($target), 'Target created', 201);
    }

    public function showTarget(Request $request, HostTarget $target): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $target->host?->agency_id, 'target');

        return ApiResponse::success($this->targetPayload($target, detailed: true));
    }

    /** Freeze a target early — normally the nightly job does this. */
    public function evaluateTarget(Request $request, HostTarget $target): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $target->host?->agency_id, 'target');

        $evaluated = $this->targets->evaluate($target, $request->user());

        return ApiResponse::success($this->targetPayload($evaluated), 'Target evaluated');
    }

    public function cancelTarget(Request $request, HostTarget $target): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $target->host?->agency_id, 'target');

        $this->targets->cancel($target, $request->user());

        return ApiResponse::success(null, 'Target cancelled');
    }

    // ----------------------------------------------------------------- helpers

    protected function targetPayload(HostTarget $target, bool $detailed = false): array
    {
        $frozen = $target->evaluated_at !== null;

        $payload = [
            'id'              => $target->id,
            'host_id'         => $target->host_id,
            'guftagu_id'      => $target->host?->user?->guftagu_id,
            'agency'          => $target->host?->agency?->name,
            'period_start'    => $target->period_start->toDateString(),
            'period_end'      => $target->period_end->toDateString(),
            'target_diamonds' => $target->target_diamonds,
            'target_hours'    => $target->target_hours,
            'target_days'     => $target->target_days,
            'status'          => $target->status,
            'is_open'         => $target->isOpen(),
            // Once evaluated the row is authoritative and must not move; before that the
            // figures are derived live from the rollup so the panel matches the ledger.
            'is_frozen'       => $frozen,
            'evaluated_at'    => $target->evaluated_at?->toIso8601ZuluString(),
            'incentive_paise' => $target->incentive_paise,
            'incentive_bp'    => $target->incentive_bp,
        ];

        if ($frozen) {
            return $payload + [
                'achieved_diamonds' => $target->achieved_diamonds,
                'achieved_hours'    => $target->achieved_hours,
                'achieved_days'     => $target->achieved_days,
                'achievement_pct'   => $target->achievement_pct,
                'source'            => 'frozen at evaluation',
            ];
        }

        return $payload + $this->targets->progress($target) + ['source' => 'derived live from host_earnings'];
    }

    protected function rowPayload(Host $host): array
    {
        return [
            'id'                 => $host->id,
            'user_id'            => $host->user_id,
            'guftagu_id'         => $host->user?->guftagu_id,
            'display_name'       => $host->user?->profile?->display_name,
            'avatar_url'         => $host->user?->profile?->avatar_url,
            'agency'             => $host->agency === null ? null : [
                'id' => $host->agency->id, 'name' => $host->agency->name, 'code' => $host->agency->code,
            ],
            'status'             => $host->status,
            'tier'               => $host->tier,
            'base_commission_bp' => $host->base_commission_bp,
            // Derived from the dates, so a contract that ended yesterday reads correctly
            // today with nothing having run.
            'under_contract'     => $host->isUnderContract(),
            'applied_at'         => $host->applied_at?->toIso8601ZuluString(),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    protected function range(array $data): array
    {
        $to = isset($data['to']) ? Carbon::parse($data['to']) : now();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->startOfMonth();

        return [$from->startOfDay(), $to->startOfDay()];
    }
}
