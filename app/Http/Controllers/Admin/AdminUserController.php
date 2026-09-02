<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Services\PermissionResolver;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Models\AdminUser;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GFT-127 — panel user management. docs/03 §9, behind `access.admin_manage`.
 */
class AdminUserController extends Controller
{
    /** Sort fields a caller may name, so `sort` cannot reach arbitrary columns. */
    protected const SORTABLE = ['id', 'name', 'email', 'status', 'created_at', 'last_login_at'];

    public function __construct(
        protected PermissionResolver $resolver,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * GET /admin/admins — offset pagination per docs/03 §2.3.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'status'   => ['sometimes', 'nullable', Rule::in(['active', 'suspended'])],
            'role'     => ['sometimes', 'nullable', Rule::exists('roles', 'key')],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort'     => ['sometimes', 'string', 'max:50'],
        ]);

        [$column, $direction] = $this->parseSort($data['sort'] ?? '-created_at');

        $query = AdminUser::query()
            ->with('role:id,key,name')
            ->when($data['q'] ?? null, function ($q, string $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->when($data['status'] ?? null, fn ($q, string $s) => $q->where('status', $s))
            ->when($data['role'] ?? null, fn ($q, string $r) => $q->whereHas('role', fn ($rq) => $rq->where('key', $r)))
            ->orderBy($column, $direction);

        $paginator = $query->paginate(
            perPage: (int) ($data['per_page'] ?? 20),
            page: (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (AdminUser $a) => $this->payload($a)
        )->all());
    }

    public function show(AdminUser $admin): JsonResponse
    {
        return ApiResponse::success($this->payload($admin->load('role:id,key,name', 'creator:id,name')));
    }

    /**
     * POST /admin/admins — create Admin / Manager / Moderator.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:150'],
            'email'    => ['required', 'string', 'email:filter', 'max:191', Rule::unique('admin_users', 'email')],
            'password' => ['required', 'string', 'min:12'],
            'role'     => ['required', 'string', Rule::exists('roles', 'key')],
            'phone'    => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $actor = $request->user();
        $role  = Role::query()->where('key', $data['role'])->firstOrFail();

        if (! $this->mayAssignRole($actor, $role)) {
            return ApiResponse::error(
                'DELEGATION_TARGET_DENIED',
                'You are not allowed to create an account with that role',
                ['role' => $role->key],
                403,
            );
        }

        $admin = AdminUser::create([
            'name'       => $data['name'],
            'email'      => strtolower($data['email']),
            'password'   => $data['password'],
            'role_id'    => $role->id,
            'phone'      => $data['phone'] ?? null,
            'status'     => 'active',
            'created_by' => $actor->id,
        ]);

        $this->audit->log($actor, 'admin.create', 'access', AdminUser::class, $admin->id, null, [
            'email' => $admin->email, 'role' => $role->key,
        ]);

        return ApiResponse::success($this->payload($admin->load('role:id,key,name')), 'Panel user created', 201);
    }

    /**
     * PATCH /admin/admins/{id}.
     */
    public function update(Request $request, AdminUser $admin): JsonResponse
    {
        $data = $request->validate([
            'name'                    => ['sometimes', 'string', 'max:150'],
            'phone'                   => ['sometimes', 'nullable', 'string', 'max:20'],
            'role'                    => ['sometimes', 'string', Rule::exists('roles', 'key')],
            'mfa_enabled'             => ['sometimes', 'boolean'],
            'session_timeout_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1440'],
        ]);

        $actor = $request->user();

        if (! $this->mayManage($actor, $admin)) {
            return ApiResponse::error('DELEGATION_TARGET_DENIED', 'You are not allowed to manage that account', null, 403);
        }

        $before = $admin->only(['name', 'phone', 'role_id', 'mfa_enabled', 'session_timeout_minutes']);

        if (isset($data['role'])) {
            $role = Role::query()->where('key', $data['role'])->firstOrFail();

            if (! $this->mayAssignRole($actor, $role)) {
                return ApiResponse::error(
                    'DELEGATION_TARGET_DENIED',
                    'You are not allowed to assign that role',
                    ['role' => $role->key],
                    403,
                );
            }

            $admin->role_id = $role->id;
        }

        $admin->fill(array_intersect_key($data, array_flip(['name', 'phone', 'mfa_enabled', 'session_timeout_minutes'])));
        $admin->save();

        // A role change changes the baseline, so the cached set is stale.
        $this->resolver->flushFor($admin->id);

        $this->audit->log($actor, 'admin.update', 'access', AdminUser::class, $admin->id, $before, $data);

        return ApiResponse::success($this->payload($admin->fresh('role')), 'Panel user updated');
    }

    /**
     * POST /admin/admins/{id}/status — activate / suspend.
     */
    public function setStatus(Request $request, AdminUser $admin): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $actor = $request->user();

        if ($actor->is($admin)) {
            return ApiResponse::error('BAD_REQUEST', 'You cannot change your own status', null, 400);
        }

        if (! $this->mayManage($actor, $admin)) {
            return ApiResponse::error('DELEGATION_TARGET_DENIED', 'You are not allowed to manage that account', null, 403);
        }

        $before = $admin->status;

        $admin->update(['status' => $data['status']]);

        if ($data['status'] === 'suspended') {
            // Suspension must end live sessions, not just block the next login.
            $admin->tokens()->get()->each(function ($token) {
                EnforceIdleTimeout::forget($token->id);
                $token->delete();
            });

            $this->resolver->flushFor($admin->id);
        }

        $this->audit->log($actor, 'admin.status', 'access', AdminUser::class, $admin->id, ['status' => $before], [
            'status' => $data['status'], 'reason' => $data['reason'] ?? null,
        ]);

        return ApiResponse::success(['status' => $admin->status], 'Status updated');
    }

    // ------------------------------------------------------------------ internals

    /**
     * Only a Super Admin may mint another Super Admin; otherwise the delegation ladder
     * from docs/01 §5.3 applies.
     */
    protected function mayAssignRole(AdminUser $actor, Role $role): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        return $actor->roleKey() === Role::ADMIN
            && in_array($role->key, [Role::MANAGER, Role::MODERATOR], true);
    }

    protected function mayManage(AdminUser $actor, AdminUser $target): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        if ($actor->is($target)) {
            return true;
        }

        return $actor->roleKey() === Role::ADMIN
            && in_array($target->roleKey(), [Role::MANAGER, Role::MODERATOR], true);
    }

    protected function parseSort(string $sort): array
    {
        $descending = str_starts_with($sort, '-');
        $column     = ltrim($sort, '-');

        if (! in_array($column, self::SORTABLE, true)) {
            $column     = 'created_at';
            $descending = true;
        }

        return [$column, $descending ? 'desc' : 'asc'];
    }

    protected function payload(AdminUser $admin): array
    {
        return [
            'id'                      => $admin->id,
            'name'                    => $admin->name,
            'email'                   => $admin->email,
            'phone'                   => $admin->phone,
            'avatar_url'              => $admin->avatar_url,
            'status'                  => $admin->status,
            'mfa_enabled'             => (bool) $admin->mfa_enabled,
            'session_timeout_minutes' => $admin->session_timeout_minutes,
            'role'                    => $admin->role === null ? null : [
                'id'   => $admin->role->id,
                'key'  => $admin->role->key,
                'name' => $admin->role->name,
            ],
            'last_login_at' => $admin->last_login_at?->toIso8601ZuluString(),
            'created_at'    => $admin->created_at?->toIso8601ZuluString(),
        ];
    }
}
