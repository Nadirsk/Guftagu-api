<?php

namespace App\Http\Middleware;

use App\Domain\Access\Exceptions\PermissionException;
use App\Domain\Access\Services\PermissionResolver;
use App\Models\AdminUser;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GFT-116 — `->middleware('permission:rooms.force_close')`. Deny by default.
 *
 * Several keys may be listed; ALL are required. A route with no permission argument is
 * refused outright rather than allowed, so attaching this middleware can never accidentally
 * widen access through a typo.
 */
class EnsurePermission
{
    public function __construct(protected PermissionResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $admin = $request->user();

        if (! $admin instanceof AdminUser) {
            return ApiResponse::error('UNAUTHENTICATED', 'Authentication required', null, 401);
        }

        if ($permissions === []) {
            // A misconfigured route is a bug, and the safe reading of a bug is "no".
            report(new \LogicException("Route [{$request->path()}] uses permission middleware with no key."));

            return ApiResponse::error('PERMISSION_DENIED', 'This action is not available', null, 403);
        }

        foreach ($permissions as $permission) {
            if (! $this->resolver->has($admin, $permission)) {
                return PermissionException::denied($permission)->render();
            }
        }

        return $next($request);
    }
}
