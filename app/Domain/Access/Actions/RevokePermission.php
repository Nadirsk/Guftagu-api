<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Exceptions\PermissionException;
use App\Domain\Access\Services\PermissionResolver;
use App\Domain\Audit\AuditLogger;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\PermissionGrantLog;
use Illuminate\Support\Facades\DB;

/**
 * GFT-118 — revoke a direct grant, with cache flush.
 *
 * Revoke removes the row in admin_user_permission. It does NOT suppress a permission the
 * target holds through their role baseline — that is what an explicit deny is for
 * (DenyPermission), and conflating the two is how "I revoked it and they still have it"
 * bugs get written.
 */
class RevokePermission
{
    public function __construct(
        protected PermissionResolver $resolver,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * @param  array<int, string>  $keys
     * @return array{revoked: array<int, string>, still_held_via_role: array<int, string>, effective_count: int}
     *
     * @throws PermissionException
     */
    public function handle(AdminUser $actor, AdminUser $target, array $keys, ?string $reason = null): array
    {
        $keys = array_values(array_unique($keys));

        if ($actor->is($target)) {
            throw PermissionException::selfGrant();
        }

        if (! $actor->canDelegateTo($target)) {
            throw PermissionException::delegationTarget();
        }

        $permissions = Permission::query()->whereIn('key', $keys)->get()->keyBy('key');

        $unknown = array_values(array_diff($keys, $permissions->keys()->all()));

        if ($unknown !== []) {
            throw new PermissionException('VALIDATION_ERROR', 'Unknown permission key', ['permissions' => $unknown], 422);
        }

        $revoked = DB::transaction(function () use ($actor, $target, $keys, $permissions, $reason) {
            $revoked = [];

            foreach ($keys as $key) {
                $permission = $permissions[$key];

                $existing = DB::table('admin_user_permission')
                    ->where('admin_user_id', $target->id)
                    ->where('permission_id', $permission->id)
                    ->first();

                if ($existing === null) {
                    continue;
                }

                DB::table('admin_user_permission')->where('id', $existing->id)->delete();

                PermissionGrantLog::create([
                    'actor_id'      => $actor->id,
                    'target_id'     => $target->id,
                    'permission_id' => $permission->id,
                    'action'        => 'revoke',
                    'effect_before' => $existing->effect,
                    'effect_after'  => null,
                    'reason'        => $reason,
                    'ip'            => request()->ip(),
                ]);

                $revoked[] = $key;
            }

            return $revoked;
        });

        $this->resolver->flushFor($target->id);

        $effective = $this->resolver->effectiveFor($target->fresh());

        // Tell the caller plainly when a revoke changed nothing visible, because the
        // role baseline still confers the permission.
        $stillHeld = array_values(array_intersect($revoked, $effective->all()));

        $this->audit->log(
            $actor,
            'permission.revoke',
            'access',
            AdminUser::class,
            $target->id,
            ['permissions' => $revoked],
            ['still_held_via_role' => $stillHeld, 'reason' => $reason],
        );

        return [
            'revoked'             => $revoked,
            'still_held_via_role' => $stillHeld,
            'effective_count'     => $effective->count(),
        ];
    }
}
