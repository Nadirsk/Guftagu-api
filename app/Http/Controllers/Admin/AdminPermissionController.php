<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Actions\DenyPermission;
use App\Domain\Access\Actions\GrantPermission;
use App\Domain\Access\Actions\RevokePermission;
use App\Domain\Access\Services\PermissionResolver;
use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\PermissionGrantLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Epic A.11 — delegation endpoints. docs/03 §9.
 *
 * Every mutating method here is a thin shell over an Action: the guard lives in the
 * domain layer so it cannot be bypassed by reaching a different entry point.
 */
class AdminPermissionController extends Controller
{
    public function __construct(protected PermissionResolver $resolver)
    {
    }

    /**
     * GET /admin/admins/{id}/permissions — GFT-126.
     * "Effective set with origin (role vs direct)."
     */
    public function show(AdminUser $admin): JsonResponse
    {
        return ApiResponse::success([
            'admin' => [
                'id'   => $admin->id,
                'name' => $admin->name,
                'role' => $admin->role?->key,
            ],
            'effective_keys' => $this->resolver->effectiveFor($admin)->values(),
            'detail'         => $this->resolver->detailedFor($admin),
        ]);
    }

    /**
     * POST /admin/admins/{id}/permissions — GFT-117.
     */
    public function grant(Request $request, AdminUser $admin, GrantPermission $action): JsonResponse
    {
        $data = $request->validate([
            'permissions'   => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::exists('permissions', 'key')],
            'scope'         => ['sometimes', 'nullable', 'array'],
            'scope.room_categories'   => ['sometimes', 'array'],
            'scope.room_categories.*' => ['integer'],
            'scope.agencies'          => ['sometimes', 'array'],
            'scope.agencies.*'        => ['integer'],
            'scope.shift'             => ['sometimes', 'array'],
            'scope.shift.from'        => ['required_with:scope.shift', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'scope.shift.to'          => ['required_with:scope.shift', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'scope.shift.tz'          => ['sometimes', 'string', 'timezone'],
            'expires_at'    => ['sometimes', 'nullable', 'date', 'after:now'],
            'reason'        => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $result = $action->handle(
            granter: $request->user(),
            target: $admin,
            keys: $data['permissions'],
            scope: $data['scope'] ?? null,
            expiresAt: isset($data['expires_at']) ? (string) now()->parse($data['expires_at']) : null,
            reason: $data['reason'] ?? null,
        );

        return ApiResponse::success(
            $result,
            count($result['granted']).' '.str('permission')->plural(count($result['granted'])).' granted',
        );
    }

    /**
     * DELETE /admin/admins/{id}/permissions — GFT-118.
     */
    public function revoke(Request $request, AdminUser $admin, RevokePermission $action): JsonResponse
    {
        $data = $request->validate([
            'permissions'   => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::exists('permissions', 'key')],
            'reason'        => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $result = $action->handle($request->user(), $admin, $data['permissions'], $data['reason'] ?? null);

        return ApiResponse::success($result, count($result['revoked']).' revoked');
    }

    /**
     * POST /admin/admins/{id}/permissions/deny — GFT-118.
     */
    public function deny(Request $request, AdminUser $admin, DenyPermission $action): JsonResponse
    {
        $data = $request->validate([
            'permissions'   => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::exists('permissions', 'key')],
            'reason'        => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $result = $action->handle($request->user(), $admin, $data['permissions'], $data['reason'] ?? null);

        return ApiResponse::success($result, count($result['denied']).' denied');
    }

    /**
     * GET /admin/admins/{id}/permission-log — grant history, behind `access.audit_view`.
     */
    public function log(Request $request, AdminUser $admin): JsonResponse
    {
        $data = $request->validate([
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = PermissionGrantLog::query()
            ->with(['actor:id,name', 'permission:id,key'])
            ->where('target_id', $admin->id)
            ->orderByDesc('id')
            ->paginate(
                perPage: (int) ($data['per_page'] ?? 20),
                page: (int) ($data['page'] ?? 1),
            );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(fn (PermissionGrantLog $row) => [
            'id'            => $row->id,
            'action'        => $row->action,
            'permission'    => $row->permission?->key,
            'effect_before' => $row->effect_before,
            'effect_after'  => $row->effect_after,
            'scope'         => $row->scope,
            'reason'        => $row->reason,
            'actor'         => $row->actor === null ? null : ['id' => $row->actor->id, 'name' => $row->actor->name],
            'ip'            => $row->ip,
            'created_at'    => $row->created_at?->toIso8601ZuluString(),
        ])->all());
    }
}
