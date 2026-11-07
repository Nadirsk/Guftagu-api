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
        $user = $request->user();

        $column = $rule->metric === 'diamonds_earned' ? 'lifetime_diamonds_earned' : 'lifetime_coins_spent';

        $myScore = DB::table('wallets')->where('user_id', $user->id)->value($column) ?? 0;

        if ($myScore < max(1, $rule->min_threshold)) {
            return ApiResponse::success(['rank' => null, 'score' => $myScore], 'Below the ranking threshold');
        }

        $rank = 1 + DB::table('wallets')
            ->join('users', 'users.id', '=', 'wallets.user_id')
            ->whereNull('users.deleted_at')
            ->where('users.status', 'active')
            ->where($column, '>', $myScore)
            ->count();

        return ApiResponse::success(['rank' => $rank, 'score' => $myScore]);
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
            'board'  => ['sometimes', Rule::in(['wealth', 'charm'])],
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
