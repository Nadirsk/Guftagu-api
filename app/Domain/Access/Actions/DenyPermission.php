<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Exceptions\PermissionException;
use App\Domain\Access\Services\MfaReauthGate;
use App\Domain\Access\Services\PermissionResolver;
use App\Domain\Audit\AuditLogger;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\PermissionGrantLog;
use Illuminate\Support\Facades\DB;

/**
 * GFT-118 — an explicit deny over a role grant.
 *
 * This is how you take one permission away from someone whose role baseline includes it,
 * without inventing a new role. The resolver subtracts denies last, so a deny always wins.
 *
 * The escalation guard applies here too: an Admin who does not hold a permission cannot
 * deny it either. That looks conservative but is deliberate — a deny is a change to
 * someone else's authority, and the same "you may only delegate what you hold" rule keeps
 * the audit story coherent.
 */
class DenyPermission
{
    public function __construct(
        protected PermissionResolver $resolver,
        protected AuditLogger $audit,
        protected MfaReauthGate $reauth,
    ) {
    }

    /**
     * @param  array<int, string>  $keys
     * @return array{denied: array<int, string>, effective_count: int}
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

        if (! $actor->isSuperAdmin()) {
            $ungranted = $this->resolver->missingFrom($actor, $keys);

            if ($ungranted !== []) {
                throw PermissionException::escalation($ungranted);
            }
        }

        $highRisk = $permissions->filter->isHighRisk()->keys()->values()->all();

        if ($highRisk !== [] && ! $this->reauth->isSatisfied($actor)) {
            throw PermissionException::mfaRequired(['high_risk' => $highRisk]);
        }

        $denied = DB::transaction(function () use ($actor, $target, $keys, $permissions, $reason) {
            $denied = [];

            foreach ($keys as $key) {
                $permission = $permissions[$key];

                $existing = DB::table('admin_user_permission')
                    ->where('admin_user_id', $target->id)
                    ->where('permission_id', $permission->id)
                    ->first();

                DB::table('admin_user_permission')->updateOrInsert(
                    ['admin_user_id' => $target->id, 'permission_id' => $permission->id],
                    [
                        'effect'     => 'deny',
                        'granted_by' => $actor->id,
                        // A deny is not time-boxed and carries no scope: a partial deny
                        // would be ambiguous against a scoped allow.
                        'expires_at' => null,
                        'scope'      => null,
                        'updated_at' => now(),
                        'created_at' => $existing->created_at ?? now(),
                    ]
                );

                PermissionGrantLog::create([
                    'actor_id'      => $actor->id,
                    'target_id'     => $target->id,
                    'permission_id' => $permission->id,
                    'action'        => 'deny',
                    'effect_before' => $existing->effect ?? null,
                    'effect_after'  => 'deny',
                    'reason'        => $reason,
                    'ip'            => request()->ip(),
                ]);

                $denied[] = $key;
            }

            return $denied;
        });

        $this->resolver->flushFor($target->id);

        $this->audit->log(
            $actor,
            'permission.deny',
            'access',
            AdminUser::class,
            $target->id,
            null,
            ['permissions' => $denied, 'reason' => $reason],
        );

        return [
            'denied'          => $denied,
            'effective_count' => $this->resolver->effectiveFor($target->fresh())->count(),
        ];
    }
}
