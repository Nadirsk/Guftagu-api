<?php

namespace App\Domain\Access\Services;

use App\Models\AdminUser;
use App\Models\Permission;
use Illuminate\Support\Collection;

/**
 * GFT-115 — the one place a permission set is computed. docs/01 §5.1.
 *
 *     effective = role baseline ∪ direct allows − direct denies
 *
 * Cached per admin for 300 s (docs/02 §16) through {@see PermissionCache}, so a grant,
 * revoke or role change flushes exactly one admin's set. Enforcement is therefore
 * immediate on the next request, not eventually consistent — which the A.11 acceptance
 * criteria require ("the cache does not delay enforcement").
 */
class PermissionResolver
{
    public const TTL = PermissionCache::TTL;

    public function __construct(protected PermissionCache $cache)
    {
    }

    /**
     * @return Collection<int, string>  permission keys
     */
    public function effectiveFor(AdminUser $admin): Collection
    {
        // Super Admin short-circuits. It holds no role_permission rows on purpose —
        // a baseline for it would be a second source of truth that could drift.
        if ($admin->isSuperAdmin()) {
            return Permission::allKeys();
        }

        return $this->cache->remember(
            $admin->id,
            'effective',
            fn () => $this->compute($admin),
        );
    }

    public function has(AdminUser $admin, string $key): bool
    {
        return $this->effectiveFor($admin)->contains($key);
    }

    /**
     * @param  array<int, string>  $keys
     */
    public function hasAll(AdminUser $admin, array $keys): bool
    {
        $effective = $this->effectiveFor($admin);

        return collect($keys)->every(fn (string $k) => $effective->contains($k));
    }

    /**
     * Keys the caller holds but the target does not — used to build the grant UI
     * and by the escalation guard.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    public function missingFrom(AdminUser $admin, array $keys): array
    {
        return collect($keys)->diff($this->effectiveFor($admin))->values()->all();
    }

    /**
     * The effective set with provenance, for the A.11 effective-permission viewer
     * (GFT-126): where each key came from, and what a deny is overriding.
     *
     * @return array<int, array{key: string, module: string, action: string, risk_level: string, origin: string, expires_at: ?string, scope: ?array}>
     */
    public function detailedFor(AdminUser $admin): array
    {
        if ($admin->isSuperAdmin()) {
            return Permission::query()
                ->orderBy('module')->orderBy('action')
                ->get(['key', 'module', 'action', 'risk_level'])
                ->map(fn (Permission $p) => [
                    'key'        => $p->key,
                    'module'     => $p->module,
                    'action'     => $p->action,
                    'risk_level' => $p->risk_level,
                    'origin'     => 'super_admin',
                    'expires_at' => null,
                    'scope'      => null,
                ])->all();
        }

        $baseline = $admin->role
            ? $admin->role->permissions()->get(['permissions.id', 'key', 'module', 'action', 'risk_level'])->keyBy('key')
            : collect();

        $direct = $admin->directGrants()->notExpired()
            ->get(['permissions.id', 'key', 'module', 'action', 'risk_level'])
            ->keyBy('key');

        $rows = [];

        foreach ($baseline as $key => $perm) {
            $override = $direct->get($key);

            // A deny row over a role grant removes it from the effective set, but the
            // viewer still shows it — an operator needs to see *why* it is absent.
            $origin = match ($override?->pivot->effect) {
                'deny'  => 'denied_over_role',
                'allow' => 'role_and_direct',
                default => 'role',
            };

            $rows[$key] = [
                'key'        => $key,
                'module'     => $perm->module,
                'action'     => $perm->action,
                'risk_level' => $perm->risk_level,
                'origin'     => $origin,
                'expires_at' => $override?->pivot->expires_at,
                'scope'      => $this->decodeScope($override?->pivot->scope),
            ];
        }

        foreach ($direct as $key => $perm) {
            if (isset($rows[$key])) {
                continue;
            }

            $rows[$key] = [
                'key'        => $key,
                'module'     => $perm->module,
                'action'     => $perm->action,
                'risk_level' => $perm->risk_level,
                'origin'     => $perm->pivot->effect === 'deny' ? 'denied_direct' : 'direct_grant',
                'expires_at' => $perm->pivot->expires_at,
                'scope'      => $this->decodeScope($perm->pivot->scope),
            ];
        }

        $rows = array_values($rows);

        usort($rows, fn ($a, $b) => [$a['module'], $a['action']] <=> [$b['module'], $b['action']]);

        return $rows;
    }

    /**
     * The scope attached to a direct grant, if any (GFT-120).
     * Role baselines are never scoped — only direct grants carry a scope.
     */
    public function scopeFor(AdminUser $admin, string $key): ?array
    {
        if ($admin->isSuperAdmin()) {
            return null;
        }

        $grant = $admin->directGrants()->allow()->notExpired()
            ->where('permissions.key', $key)
            ->first();

        return $this->decodeScope($grant?->pivot->scope);
    }

    /** Clears this admin's permissions *and* their cached scopes and ban cap — one namespace. */
    public function flushFor(int $adminId): void
    {
        $this->cache->flush($adminId);
    }

    /**
     * Used when a role's baseline changes — every holder of that role is affected.
     */
    public function flushForRole(int $roleId): void
    {
        AdminUser::query()->where('role_id', $roleId)->pluck('id')
            ->each(fn (int $id) => $this->flushFor($id));
    }

    // ------------------------------------------------------------------ internals

    protected function compute(AdminUser $admin): Collection
    {
        $baseline = $admin->role
            ? $admin->role->permissions()->pluck('key')
            : collect();

        $allows = $admin->directGrants()->allow()->notExpired()->pluck('permissions.key');
        $denies = $admin->directGrants()->deny()->notExpired()->pluck('permissions.key');

        return $baseline->merge($allows)->diff($denies)->unique()->values();
    }

    protected function decodeScope(mixed $scope): ?array
    {
        if ($scope === null || $scope === '') {
            return null;
        }

        $decoded = is_array($scope) ? $scope : json_decode($scope, true);

        // An empty scope means unrestricted, per docs/02 §2.4 — normalise it to null
        // so callers have exactly one "no restriction" value to check.
        return empty($decoded) ? null : $decoded;
    }

}
