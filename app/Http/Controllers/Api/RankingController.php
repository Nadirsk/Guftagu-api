<?php

namespace App\Http\Controllers\Api;

use App\Domain\Events\LeaderboardService;
use App\Http\Controllers\Controller;
use App\Models\RankingReward;
use App\Models\RankingRule;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * D.8 — leaderboards. Only `wealth` and `charm` are computable today (the admin side's
 * own `computable_board_types` note applies here too — `room` and `agency` boards need
 * modules that do not exist yet).
 */
class RankingController extends Controller
{
    public function __construct(protected LeaderboardService $leaderboards)
    {
    }

    /** GET /rankings — `?board=wealth|charm&period=`. */
    public function board(Request $request): JsonResponse
    {
        $rule = $this->resolveRule($request);

        return ApiResponse::success([
            'board'   => $rule->board_type,
            'period'  => $rule->period,
            'entries' => $this->leaderboards->board($rule),
        ]);
    }

    /** GET /rankings/me — `?board=wealth|charm&period=`. */
    public function me(Request $request): JsonResponse
    {
        $rule = $this->resolveRule($request);

        $standing = $this->leaderboards->standing($rule, $request->user()->id);

        return ApiResponse::success(
            $standing,
            $standing['rank'] === null ? 'Below the ranking threshold' : 'OK',
        );
    }

    /**
     * GET /rankings/rewards — `?board=wealth|charm&period=`. What the Weekly List card for
     * each rank shows: whatever the admin panel set on that rule, up to three per rank.
     * Rewards that could not be paid (a store item gone or switched off) are left out.
     */
    public function rewards(Request $request): JsonResponse
    {
        $rule = $this->resolveRule($request);

        $rewards = RankingReward::query()
            ->with('storeItem')
            ->where('rule_key', $rule->key)
            ->where('is_active', true)
            ->orderBy('rank_from')
            ->orderBy('id')
            ->get()
            ->filter(fn (RankingReward $r) => $r->isPayable());

        return ApiResponse::success([
            'board'   => $rule->board_type,
            'period'  => $rule->period,
            'rewards' => $rewards->map(fn (RankingReward $r) => [
                'id'            => $r->id,
                'rank_from'     => $r->rank_from,
                'rank_to'       => $r->rank_to,
                'reward_type'   => $r->reward_type,
                'reward_value'  => $r->reward_value,
                'duration_days' => $r->duration_days,
                'item'          => $r->itemPayload(),
            ])->values()->all(),
        ]);
    }

    protected function resolveRule(Request $request): RankingRule
    {
        $data = $request->validate([
            // `cp` only has rewards (GET /rankings/rewards); its board and `me` come back empty.
            'board'  => ['sometimes', Rule::in(['wealth', 'charm', 'cp'])],
            'period' => ['sometimes', Rule::in(RankingRule::PERIODS)],
        ]);

        $rule = RankingRule::query()
            ->where('board_type', $data['board'] ?? 'wealth')
            ->where('period', $data['period'] ?? 'all_time')
            ->where('is_active', true)
            ->first();

        abort_unless($rule !== null, 404, 'No ranking is configured for that board and period.');

        return $rule;
    }
}
