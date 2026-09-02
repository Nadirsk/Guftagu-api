<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Events\LeaderboardService;
use App\Http\Controllers\Controller;
use App\Models\LeaderboardSnapshot;
use App\Models\RankingReward;
use App\Models\RankingRewardPayout;
use App\Models\RankingRule;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule as ValidationRule;

/**
 * GFT-096 / GFT-098 / GFT-101 — ranking rules, boards and reward payouts (A.9c, A.9d).
 */
class RankingController extends Controller
{
    public function __construct(
        protected LeaderboardService $leaderboards,
        protected AuditLogger $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $rules = RankingRule::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('board_type')->orderBy('period')
            ->get();

        return ApiResponse::success([
            'rules' => $rules->map(fn (RankingRule $rule) => $this->payload($rule)),
            'board_types' => RankingRule::BOARD_TYPES,
            'periods'     => RankingRule::PERIODS,
            'metrics'     => RankingRule::METRICS,
            // Rooms and agencies need their own metrics; only user boards are computable
            // until those modules land, so the panel can grey the rest out honestly.
            'computable_board_types' => ['wealth', 'charm'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateRule($request);

        $rule = RankingRule::create($data);

        $this->audit->log($request->user(), 'ranking.rule_create', 'rankings', RankingRule::class, $rule->id, null, $data);

        return ApiResponse::success($this->payload($rule), 'Ranking rule created', 201);
    }

    public function update(Request $request, RankingRule $rule): JsonResponse
    {
        $data = $this->validateRule($request, false, $rule);

        $before = $rule->only(array_keys($data));
        $rule->fill($data)->save();

        $this->audit->log($request->user(), 'ranking.rule_update', 'rankings', RankingRule::class, $rule->id, $before, $data);

        return ApiResponse::success($this->payload($rule->fresh()), 'Ranking rule updated');
    }

    /** The live board — A.9c's threshold is applied inside the query. */
    public function board(RankingRule $rule): JsonResponse
    {
        [$start, $end] = $this->leaderboards->periodFor($rule);

        return ApiResponse::success([
            'rule'    => $this->payload($rule),
            'period'  => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'entries' => $this->leaderboards->board($rule),
            'source'  => [
                'live' => false,
                'note' => 'Computed from wallet lifetime counters. docs/02 §8 puts live boards in Redis ZSETs; that arrives with the realtime layer. Snapshots and payouts are unaffected.',
            ],
        ]);
    }

    /** Freeze the board as the record for this period. */
    public function snapshot(Request $request, RankingRule $rule): JsonResponse
    {
        [$start, $end] = $this->leaderboards->periodFor($rule);

        $written = $this->leaderboards->snapshot($rule, $start, $end);

        $this->audit->log(
            $request->user(),
            'ranking.snapshot',
            'rankings',
            RankingRule::class,
            $rule->id,
            null,
            ['period_start' => $start->toDateString(), 'rows' => $written],
        );

        return ApiResponse::success([
            'rows'         => $written,
            'period_start' => $start->toDateString(),
        ], "Snapshot taken — {$written} places recorded");
    }

    public function snapshots(Request $request, RankingRule $rule): JsonResponse
    {
        $periods = LeaderboardSnapshot::query()
            ->selectRaw('period_start, period_end, COUNT(*) AS places, MAX(score) AS top_score')
            ->where('rule_key', $rule->key)
            ->groupBy('period_start', 'period_end')
            ->orderByDesc('period_start')
            ->limit(24)
            ->get();

        return ApiResponse::success($periods->map(fn ($row) => [
            'period_start' => $row->period_start,
            'period_end'   => $row->period_end,
            'places'       => (int) $row->places,
            'top_score'    => (int) $row->top_score,
            'paid'         => RankingRewardPayout::query()
                ->whereIn('snapshot_id', LeaderboardSnapshot::query()
                    ->where('rule_key', $rule->key)
                    ->where('period_start', $row->period_start)
                    ->select('id'))
                ->where('status', RankingRewardPayout::PAID)
                ->count(),
        ])->all());
    }

    /** GFT-098 / A.9d — idempotent. Running it twice pays once. */
    public function payRewards(Request $request, RankingRule $rule): JsonResponse
    {
        $data = $request->validate([
            'period_start' => ['sometimes', 'nullable', 'date'],
        ]);

        $periodStart = isset($data['period_start'])
            ? Carbon::parse($data['period_start'])
            : $this->leaderboards->periodFor($rule)[0];

        $result = $this->leaderboards->payRewards($rule, $periodStart, $request->user());

        $this->audit->log(
            $request->user(),
            'ranking.rewards_paid',
            'rankings',
            RankingRule::class,
            $rule->id,
            null,
            [...$result, 'period_start' => $periodStart->toDateString()],
        );

        return ApiResponse::success($result, sprintf(
            '%d paid%s',
            $result['paid'],
            $result['skipped'] > 0 ? ", {$result['skipped']} already had theirs" : '',
        ));
    }

    // ---------------------------------------------------------------- rewards

    public function rewards(RankingRule $rule): JsonResponse
    {
        $rewards = RankingReward::query()
            ->where('rule_key', $rule->key)
            ->orderBy('rank_from')
            ->get();

        return ApiResponse::success($rewards->map(fn (RankingReward $r) => [
            'id'           => $r->id,
            'rank_from'    => $r->rank_from,
            'rank_to'      => $r->rank_to,
            'reward_type'  => $r->reward_type,
            'reward_value' => $r->reward_value,
            'is_active'    => $r->is_active,
        ])->all());
    }

    public function addReward(Request $request, RankingRule $rule): JsonResponse
    {
        $data = $request->validate([
            'rank_from'    => ['required', 'integer', 'min:1'],
            'rank_to'      => ['required', 'integer', 'gte:rank_from'],
            'reward_type'  => ['required', ValidationRule::in(['coins', 'diamonds'])],
            'reward_value' => ['required', 'integer', 'min:1', 'max:100000000'],
        ]);

        $clash = RankingReward::query()
            ->where('rule_key', $rule->key)
            ->where('is_active', true)
            ->where('rank_from', '<=', $data['rank_to'])
            ->where('rank_to', '>=', $data['rank_from'])
            ->first();

        if ($clash !== null) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'That rank range overlaps an existing reward band.',
                ['overlapping' => ['rank_from' => $clash->rank_from, 'rank_to' => $clash->rank_to]],
                422,
            );
        }

        $reward = RankingReward::create([...$data, 'rule_key' => $rule->key]);

        return ApiResponse::success(['id' => $reward->id], 'Reward band added', 201);
    }

    // ----------------------------------------------------------------- shared

    /** @return array<string, mixed> */
    protected function validateRule(Request $request, bool $creating = true, ?RankingRule $rule = null): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'key' => [
                $creating ? 'required' : 'sometimes',
                'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/',
                ValidationRule::unique('ranking_rules', 'key')->ignore($rule?->id),
            ],
            'board_type'    => [$required, ValidationRule::in(RankingRule::BOARD_TYPES)],
            'period'        => [$required, ValidationRule::in(RankingRule::PERIODS)],
            'metric'        => [$required, ValidationRule::in(RankingRule::METRICS)],
            'min_threshold' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'top_n'         => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'is_active'     => ['sometimes', 'boolean'],
        ]);
    }

    protected function payload(RankingRule $rule): array
    {
        return [
            'id'            => $rule->id,
            'key'           => $rule->key,
            'board_type'    => $rule->board_type,
            'period'        => $rule->period,
            'metric'        => $rule->metric,
            'min_threshold' => $rule->min_threshold,
            'top_n'         => $rule->top_n,
            'is_active'     => $rule->is_active,
            // Room and agency boards need modules that do not exist yet.
            'computable'    => in_array($rule->board_type, ['wealth', 'charm'], true),
        ];
    }
}
