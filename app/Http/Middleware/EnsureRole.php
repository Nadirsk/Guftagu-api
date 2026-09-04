<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `->middleware('role:it_admin')` — a hard identity check, not a permission grant.
 *
 * Unlike EnsurePermission, this deliberately does NOT go through PermissionResolver,
 * so Super Admin's blanket permission bypass does not apply here and a direct grant of
 * the matching permission key cannot substitute for it either. Use only where a screen
 * must be restricted to one specific role's holders and nobody else — e.g. system logs,
 * which even Super Admin should not see (see routes/api.php).
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $admin = $request->user();

        if (! $admin instanceof AdminUser) {
            return ApiResponse::error('UNAUTHENTICATED', 'Authentication required', null, 401);
        }

        if ($roles === [] || ! in_array($admin->roleKey(), $roles, true)) {
            return ApiResponse::error(
                'PERMISSION_DENIED',
                'You do not have permission to perform this action',
                ['role' => $roles],
                403,
            );
        }

        return $next($request);
    }
}
