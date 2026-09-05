<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mobile counterpart of {@see EnsureAdminActive} — docs/03 §"Two consumers, two route
 * groups, two middleware stacks" names it `user.active`.
 *
 * Status is re-derived on every request rather than trusted from the token, and it is
 * `effectiveStatus()` that is asked, not the raw column: a 24-hour suspension writes
 * `status = suspended` plus a sanction with an `expires_at`, and nothing rewrites the
 * column when that moment passes. Reading the column alone locks people out forever
 * (see {@see User::isActive()}).
 *
 * Tokens are revoked on a *ban*, not on a suspension — a temporary suspension that forces a
 * fresh OTP login when it lapses turns a 24-hour timeout into a support ticket.
 */
class EnsureUserActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            if ($user->effectiveStatus() === User::STATUS_BANNED || $user->effectiveStatus() === User::STATUS_DELETED) {
                $user->tokens()->delete();
            }

            return ApiResponse::error(
                'ACCOUNT_INACTIVE',
                'This account is not currently active.',
                ['status' => $user->effectiveStatus()],
                403,
            );
        }

        // `last_active_at` drives presence (D.3b) and the "recently active first" ordering
        // in people search. Written at most once a minute: every request would be a write
        // per request on the busiest table in the schema.
        if ($user instanceof User
            && ($user->last_active_at === null || $user->last_active_at->lt(now()->subMinute()))) {
            $user->forceFill(['last_active_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
