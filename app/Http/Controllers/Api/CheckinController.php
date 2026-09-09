<?php

namespace App\Http\Controllers\Api;

use App\Domain\Engagement\CheckinService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Epic D.7c — the 7-day check-in streak (docs/03 §"Mobile — rewards").
 */
class CheckinController extends Controller
{
    public function __construct(protected CheckinService $checkin)
    {
    }

    /**
     * GET /checkin — the streak calendar screen.
     */
    public function status(Request $request): JsonResponse
    {
        return ApiResponse::success($this->checkin->status($request->user()));
    }

    /**
     * POST /checkin — claims today's reward.
     */
    public function claim(Request $request): JsonResponse
    {
        $result = $this->checkin->claim($request->user());

        if (! $result['ok']) {
            return match ($result['error']) {
                'already_claimed' => ApiResponse::error('ALREADY_CLAIMED', "Today's reward is already claimed", null, 409),
                'not_configured'  => ApiResponse::error('NOT_FOUND', 'No reward is configured for today yet', null, 404),
                default           => ApiResponse::error('BAD_REQUEST', 'Could not claim today\'s reward', null, 400),
            };
        }

        $checkin = $result['checkin'];

        return ApiResponse::success([
            'streak_day'   => $checkin->streak_day,
            'reward_type'  => $checkin->reward_type,
            'reward_value' => $checkin->reward_value,
        ], 'Reward claimed');
    }
}
