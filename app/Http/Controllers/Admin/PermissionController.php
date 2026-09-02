<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Services\PermissionResolver;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * docs/03 §9 — the catalogue, and the caller's delegable subset (GFT-119).
 */
class PermissionController extends Controller
{
    public function __construct(protected PermissionResolver $resolver)
    {
    }

    /**
     * GET /admin/permissions — full catalogue, grouped by module.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'modules' => $this->grouped(Permission::query()->orderBy('module')->orderBy('action')->get()),
        ]);
    }

    /**
     * GET /admin/permissions/grantable — GFT-119.
     *
     * "Only what the caller may delegate — the panel builds its grant UI from this."
     *
     * This is a convenience for the UI, never the enforcement point: GrantPermission
     * re-checks everything server-side, because the panel can be bypassed.
     */
    public function grantable(Request $request): JsonResponse
    {
        $admin = $request->user();

        $keys = $this->resolver->effectiveFor($admin);

        $permissions = Permission::query()
            ->whereIn('key', $keys)
            ->orderBy('module')->orderBy('action')
            ->get();

        $targetRoles = match ($admin->roleKey()) {
            Role::SUPER_ADMIN => Role::query()->pluck('key')->all(),
            Role::ADMIN       => [Role::MANAGER, Role::MODERATOR],
            default           => [],
        };

        return ApiResponse::success([
            // A Manager sees an empty set — they may hold permissions but may delegate none.
            'can_delegate'       => $targetRoles !== [],
            'grantable_to_roles' => array_values(array_diff($targetRoles, [Role::SUPER_ADMIN])),
            'modules'            => $this->grouped($permissions),
            'total'              => $permissions->count(),
        ]);
    }

    protected function grouped(\Illuminate\Support\Collection $permissions): array
    {
        return $permissions
            ->groupBy('module')
            ->map(fn ($items, $module) => [
                'module'      => $module,
                'permissions' => $items->map(fn (Permission $p) => [
                    'id'         => $p->id,
                    'key'        => $p->key,
                    'action'     => $p->action,
                    'name'       => $p->name,
                    'risk_level' => $p->risk_level,
                ])->values(),
            ])
            ->values()
            ->all();
    }
}
