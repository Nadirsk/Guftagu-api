<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * GFT-006 / A.1c — idle-session expiry.
 *
 * Acceptance: "Given a Super Admin sets the session timeout to 30 minutes, when an admin is
 * idle for 31 minutes, then their next request returns 401 TOKEN_EXPIRED."
 *
 * Implemented with a per-token Redis marker whose TTL *is* the timeout, rather than by
 * reading Sanctum's `last_used_at` — Sanctum has already refreshed that by the time any
 * middleware runs, so comparing against it would never expire anything.
 *
 * The marker missing means expired. That fails closed: if Redis is cleared, admins are
 * logged out rather than granted an unbounded session.
 */
class EnforceIdleTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if (! $admin instanceof AdminUser) {
            return $next($request);
        }

        $token = $admin->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        $minutes = $admin->sessionTimeoutMinutes();

        // 0 or negative disables idle expiry — an explicit operator choice, not a default.
        if ($minutes <= 0) {
            return $next($request);
        }

        $key = static::key($token->id);

        if (! Cache::has($key)) {
            $token->delete();

            return ApiResponse::error(
                'TOKEN_EXPIRED',
                'Your session expired after '.$minutes.' minutes of inactivity',
                ['idle_timeout_minutes' => $minutes],
                401,
            );
        }

        // Sliding window: every authenticated request pushes the expiry out again.
        static::touch($token->id, $minutes);

        return $next($request);
    }

    public static function touch(int|string $tokenId, int $minutes): void
    {
        Cache::put(static::key($tokenId), now()->toIso8601ZuluString(), now()->addMinutes($minutes));
    }

    public static function forget(int|string $tokenId): void
    {
        Cache::forget(static::key($tokenId));
    }

    protected static function key(int|string $tokenId): string
    {
        return "admin:session:{$tokenId}";
    }
}
