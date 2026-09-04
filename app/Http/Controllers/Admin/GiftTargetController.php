<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Services\ScopeFilter;
use App\Domain\Agency\GiftTargetService;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\GiftTargetPolicy;
use App\Models\Host;
use App\Models\HostGiftTargetResult;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The monthly gift-target ladder — mehfil's "Policies" screen, ported. See
 * `gift_target_policies`'s migration for how this differs from A.8b's host targets.
 */
class GiftTargetController extends Controller
{
    public function __construct(
        protected GiftTargetService $service,
        protected AuditLogger $audit,
        protected ScopeFilter $scope,
    ) {
    }

    // ------------------------------------------------------------- the ladder

    public function index(Request $request): JsonResponse
    {
        $policies = GiftTargetPolicy::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('target_coins')
            ->get();

        return ApiResponse::success($policies->map(fn (GiftTargetPolicy $p) => $this->policyPayload($p))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePolicy($request);

        $policy = GiftTargetPolicy::create($data);

        $this->audit->log($request->user(), 'gift_target_policy.create', 'agency', GiftTargetPolicy::class, $policy->id, null, $data);

        return ApiResponse::success($this->policyPayload($policy), 'Target policy created', 201);
    }

    public function update(Request $request, GiftTargetPolicy $policy): JsonResponse
    {
        $data = $this->validatePolicy($request, false);

        $before = $policy->only(array_keys($data));
        $policy->fill($data)->save();

        $this->audit->log($request->user(), 'gift_target_policy.update', 'agency', GiftTargetPolicy::class, $policy->id, $before, $data);

        return ApiResponse::success($this->policyPayload($policy->fresh()), 'Target policy updated');
    }

    // ------------------------------------------------------------- evaluation

    /** GET /admin/hosts/gift-targets?period=YYYY-MM — results for a month, scoped. */
    public function results(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $query = HostGiftTargetResult::query()
            ->with(['host.user.profile:id,user_id,display_name', 'host.agency:id,name', 'policy'])
            ->where('period', $data['period'])
            ->whereHas('host', fn ($q) => $this->scope->applyAgency($q, $request->user()))
            ->orderByDesc('coins_sent');

        return ApiResponse::success($query->get()->map(fn (HostGiftTargetResult $r) => $this->resultPayload($r))->all());
    }

    /** POST /admin/hosts/{host}/gift-targets/evaluate — one host, one month. */
    public function evaluateHost(Request $request, Host $host): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $host->agency_id, 'host');

        $data = $request->validate(['period' => ['required', 'regex:/^\d{4}-\d{2}$/']]);

        $result = $this->service->evaluate($host, $data['period'], $request->user());

        return ApiResponse::success($this->resultPayload($result->load(['host.user.profile', 'host.agency', 'policy'])), 'Evaluated');
    }

    /**
     * POST /admin/hosts/gift-targets/evaluate-all — every host for a month, in one pass.
     * Not scope-gated per-host since it deliberately processes everyone; the permission
     * itself gates who may run it at all.
     */
    public function evaluateAll(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['required', 'regex:/^\d{4}-\d{2}$/']]);

        $summary = $this->service->evaluateAll($data['period'], $request->user());

        return ApiResponse::success($summary, "{$summary['evaluated']} hosts evaluated, {$summary['skipped']} already done");
    }

    /**
     * GET /admin/hosts/gift-targets/tracker — one row per active host, always. Whichever
     * month is requested (current month by default): a host already evaluated for it
     * shows the frozen result, everyone else shows live progress toward the ladder — the
     * same "derived while running, frozen once decided" split the Hosts page's own
     * Targets tab already uses for A.8b targets, just against coins-sent + minutes-live
     * instead of diamonds.
     */
    public function tracker(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['sometimes', 'nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $period = $data['period'] ?? now()->format('Y-m');

        $hosts = $this->scope->applyAgency(
            Host::query()->active()->with(['user.profile:id,user_id,display_name', 'agency:id,name']),
            $request->user(),
        )->get();

        $frozen = HostGiftTargetResult::query()
            ->with('policy')
            ->where('period', $period)
            ->whereIn('host_id', $hosts->pluck('id'))
            ->whereNotNull('evaluated_at')
            ->get()
            ->keyBy('host_id');

        $rows = $hosts->map(function (Host $host) use ($period, $frozen) {
            $result = $frozen->get($host->id);

            if ($result !== null) {
                $coinsPct = $result->policy !== null ? min(999, (int) floor($result->coins_sent * 100 / max(1, $result->policy->target_coins))) : null;
                $minutesPct = $result->policy !== null ? min(999, (int) floor($result->minutes_live * 100 / max(1, $result->policy->time_minutes))) : null;

                return $this->trackerPayload($host, $period, [
                    'coins_sent'    => $result->coins_sent,
                    'minutes_live'  => $result->minutes_live,
                    'target'        => $result->policy === null ? null : [
                        'id' => $result->policy->id, 'target_coins' => $result->policy->target_coins, 'time_minutes' => $result->policy->time_minutes,
                    ],
                    'coins_pct'     => $coinsPct,
                    'minutes_pct'   => $minutesPct,
                    'overall_pct'   => ($coinsPct !== null && $minutesPct !== null) ? (int) round(($coinsPct + $minutesPct) / 2) : null,
                ], $result->host_reward_paise, $result->agency_reward_paise, true);
            }

            $progress = $this->service->liveProgress($host, $period);

            return $this->trackerPayload($host, $period, $progress, 0, 0, false);
        });

        return ApiResponse::success($rows->all());
    }

    // ----------------------------------------------------------------- shared

    /** @return array<string, mixed> */
    protected function validatePolicy(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'time_minutes'         => [$required, 'integer', 'min:0', 'max:100000'],
            'target_coins'         => [$required, 'integer', 'min:0', 'max:1000000000'],
            'host_reward_paise'    => ['sometimes', 'integer', 'min:0', 'max:10000000000'],
            'agency_reward_paise'  => ['sometimes', 'integer', 'min:0', 'max:10000000000'],
            'is_active'            => ['sometimes', 'boolean'],
        ]);
    }

    protected function policyPayload(GiftTargetPolicy $policy): array
    {
        return [
            'id'                   => $policy->id,
            'time_minutes'         => $policy->time_minutes,
            'target_coins'         => $policy->target_coins,
            'host_reward_paise'    => $policy->host_reward_paise,
            'agency_reward_paise'  => $policy->agency_reward_paise,
            'is_active'            => $policy->is_active,
        ];
    }

    /** @param array<string, mixed> $progress */
    protected function trackerPayload(Host $host, string $period, array $progress, int $hostRewardPaise, int $agencyRewardPaise, bool $isFrozen): array
    {
        return [
            'host' => [
                'id'           => $host->id,
                'guftagu_id'   => $host->user?->guftagu_id,
                'display_name' => $host->user?->profile?->display_name,
                'agency'       => $host->agency === null ? null : ['id' => $host->agency->id, 'name' => $host->agency->name],
            ],
            'period'               => $period,
            'coins_sent'           => $progress['coins_sent'],
            'minutes_live'         => $progress['minutes_live'],
            'target'               => $progress['target'],
            'coins_pct'            => $progress['coins_pct'],
            'minutes_pct'          => $progress['minutes_pct'],
            'overall_pct'          => $progress['overall_pct'],
            'host_reward_paise'    => $hostRewardPaise,
            'agency_reward_paise'  => $agencyRewardPaise,
            'is_frozen'            => $isFrozen,
            'source'               => $isFrozen ? 'frozen at evaluation' : 'derived live from ledger',
        ];
    }

    protected function resultPayload(HostGiftTargetResult $result): array
    {
        return [
            'id'                   => $result->id,
            'host'                 => [
                'id'           => $result->host->id,
                'display_name' => $result->host->user?->profile?->display_name,
                'agency'       => $result->host->agency === null ? null : [
                    'id' => $result->host->agency->id, 'name' => $result->host->agency->name,
                ],
            ],
            'period'               => $result->period,
            'coins_sent'           => $result->coins_sent,
            'minutes_live'         => $result->minutes_live,
            'policy_id'            => $result->policy_id,
            'policy'               => $result->policy === null ? null : [
                'target_coins' => $result->policy->target_coins, 'time_minutes' => $result->policy->time_minutes,
            ],
            'host_reward_paise'    => $result->host_reward_paise,
            'agency_reward_paise'  => $result->agency_reward_paise,
            'evaluated_at'         => $result->evaluated_at?->toIso8601ZuluString(),
        ];
    }
}
