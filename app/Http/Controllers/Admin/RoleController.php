<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Services\PermissionResolver;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * docs/03 §9 — role CRUD behind `access.role_manage`. System roles are not deletable.
 */
class RoleController extends Controller
{
    public function __construct(
        protected PermissionResolver $resolver,
        protected AuditLogger $audit,
    ) {
    }

    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->withCount(['permissions', 'adminUsers'])
            // This whole route is IT Admin only (routes/api.php), and Super Admin's own
            // definition still doesn't belong in a list IT Admin can see — it "cannot be
            // scoped or limited" by design (RoleSeeder), so there is nothing here to manage.
            ->where('key', '!=', Role::SUPER_ADMIN)
            ->orderBy('id')
            ->get();

        return ApiResponse::success($roles->map(fn (Role $role) => [
            'id'               => $role->id,
            'key'              => $role->key,
            'name'             => $role->name,
            'description'      => $role->description,
            'is_system'        => $role->isSystem(),
            'permission_count' => $role->permissions_count,
            'admin_count'      => $role->admin_users_count,
        ])->all());
    }

    public function show(Role $role): JsonResponse
    {
        // Not found rather than forbidden — a 403 would confirm the role exists.
        if ($role->key === Role::SUPER_ADMIN) {
            return ApiResponse::error('NOT_FOUND', 'Role not found', null, 404);
        }

        $keys = $role->permissions()->pluck('key');

        return ApiResponse::success([
            'id'          => $role->id,
            'key'         => $role->key,
            'name'        => $role->name,
            'description' => $role->description,
            'is_system'   => $role->isSystem(),
            'permissions' => $keys->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'           => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles', 'key')],
            'name'          => ['required', 'string', 'max:100'],
            'description'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions'   => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'key')],
        ]);

        $keys = $data['permissions'] ?? [];

        // The escalation rule applies to role baselines too: building a role that holds
        // more than you do would be a trivial way around GrantPermission.
        if (! $request->user()->isSuperAdmin()) {
            $ungranted = $this->resolver->missingFrom($request->user(), $keys);

            if ($ungranted !== []) {
                return ApiResponse::error(
                    'PERMISSION_ESCALATION_DENIED',
                    'You cannot put permissions you do not hold into a role',
                    ['ungranted' => $ungranted],
                    403,
                );
            }
        }

        $role = DB::transaction(function () use ($data, $keys) {
            $role = Role::create([
                'key'         => $data['key'],
                'name'        => $data['name'],
                'description' => $data['description'] ?? null,
                'is_system'   => false,
            ]);

            $this->syncPermissions($role, $keys);

            return $role;
        });

        $this->audit->log($request->user(), 'role.create', 'access', Role::class, $role->id, null, [
            'key' => $role->key, 'permissions' => $keys,
        ]);

        return ApiResponse::success(['id' => $role->id, 'key' => $role->key], 'Role created', 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:100'],
            'description'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions'   => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'key')],
        ]);

        // A system role's identity is fixed, but its baseline is still editable —
        // docs/02 §2.4 only forbids deletion.
        if ($role->key === Role::SUPER_ADMIN && array_key_exists('permissions', $data)) {
            return ApiResponse::error(
                'BAD_REQUEST',
                'The Super Admin baseline cannot be edited — it is unrestricted by short-circuit',
                null,
                400,
            );
        }

        $before = [
            'name'        => $role->name,
            'description' => $role->description,
            'permissions' => $role->permissions()->pluck('key')->all(),
        ];

        if (array_key_exists('permissions', $data) && ! $request->user()->isSuperAdmin()) {
            $ungranted = $this->resolver->missingFrom($request->user(), $data['permissions']);

            if ($ungranted !== []) {
                return ApiResponse::error(
                    'PERMISSION_ESCALATION_DENIED',
                    'You cannot put permissions you do not hold into a role',
                    ['ungranted' => $ungranted],
                    403,
                );
            }
        }

        DB::transaction(function () use ($role, $data) {
            $role->fill(array_intersect_key($data, array_flip(['name', 'description'])))->save();

            if (array_key_exists('permissions', $data)) {
                $this->syncPermissions($role, $data['permissions']);
            }
        });

        // Changing a baseline changes what every holder of the role can do — their
        // cached sets must go, or enforcement lags by up to 300 s.
        $this->resolver->flushForRole($role->id);

        $this->audit->log($request->user(), 'role.update', 'access', Role::class, $role->id, $before, $data);

        return ApiResponse::success(null, 'Role updated');
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        if ($role->isSystem()) {
            return ApiResponse::error('BAD_REQUEST', 'System roles cannot be deleted', ['role' => $role->key], 400);
        }

        $inUse = $role->adminUsers()->count();

        if ($inUse > 0) {
            return ApiResponse::error(
                'BAD_REQUEST',
                'Reassign the admins on this role before deleting it',
                ['admin_count' => $inUse],
                400,
            );
        }

        $this->audit->log($request->user(), 'role.delete', 'access', Role::class, $role->id, [
            'key' => $role->key, 'name' => $role->name,
        ], null);

        $role->delete();

        return ApiResponse::success(null, 'Role deleted');
    }

    protected function syncPermissions(Role $role, array $keys): void
    {
        $ids = Permission::query()->whereIn('key', $keys)->pluck('id')->all();

        $role->permissions()->sync($ids);
    }
}
