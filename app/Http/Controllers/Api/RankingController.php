<?php

namespace App\Http\Controllers\Api;

use App\Domain\Events\LeaderboardService;
use App\Http\Controllers\Controller;
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
