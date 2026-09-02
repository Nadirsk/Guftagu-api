<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended admin must lose access immediately, not at next login — so status is
 * re-checked on every request rather than trusted from the token.
 */
class EnsureAdminActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if ($admin instanceof AdminUser && ! $admin->isActive()) {
            $admin->tokens()->delete();

            return ApiResponse::error('FORBIDDEN', 'This account has been suspended', null, 403);
        }

        return $next($request);
    }
}
