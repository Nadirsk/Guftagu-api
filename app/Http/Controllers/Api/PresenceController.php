<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Home "Active Now". Presence is `users.last_active_at`, which `EnsureUserActive` stamps
 * at most once a minute, so "online" here means "made a request in the last few minutes".
 */
class PresenceController extends Controller
{
    protected const WINDOW_MINUTES = 5;

    /** GET /presence/online-count */
    public function onlineCount(): JsonResponse
    {
        // Cached briefly: every home screen polls this, and the number needs no better than ~15s.
        $count = Cache::remember('presence.online_count', 15, fn () => User::query()
            ->where('status', 'active')
            ->where('last_active_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->count());

        return ApiResponse::success(['online' => $count]);
    }
}
