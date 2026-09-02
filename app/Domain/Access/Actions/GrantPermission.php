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
 * GFT-117 — the escalation guard. docs/01 §5.3.
 *
 * This is the single server-side chokepoint for delegation. The A.11 acceptance criteria
 * are explicit that hiding an option in the UI does not satisfy them: an Admin lacking
 * `payouts.approve` must be refused here even on a direct API call with the panel bypassed.
 *
 * Order of checks matters — self-grant, then target, then superset, then MFA — so the
 * caller learns the most fundamental reason for refusal rather than a downstream one.
 */
class GrantPermission
{
    public function __construct(
        protected PermissionResolver $resolver,
        protected AuditLogger $audit,
        protected MfaReauthGate $reauth,
    ) {
    }

    /**
     * @param  array<int, string>  $keys
     * @return array{granted: array<int, string>, effective_count: int}
     *
     * @throws PermissionException
     */
    public function handle(
        AdminUser $granter,
        AdminUser $target,
        array $keys,
        ?array $scope = null,
        ?string $expiresAt = null,
        ?string $reason = null,
    ): array {
        $keys = array_values(array_unique($keys));

        // ---- guard 1: never to oneself. A Super Admin is not exempt: self-grant is how
        // an account with a narrowed role would climb back out of it.
        if ($granter->is($target)) {
            $this->refuse($granter, $target, $keys, 'self_grant');

            throw PermissionException::selfGrant();
        }

        // ---- guard 2: SA → anyone, Admin → {Manager, Moderator}, everyone else → nobody.
        if (! $granter->canDelegateTo($target)) {
            $this->refuse($granter, $target, $keys, 'delegation_target');

            throw PermissionException::delegationTarget();
        }

        $permissions = Permission::query()->whereIn('key', $keys)->get()->keyBy('key');

        // Defensive: the FormRequest validates this too, but the action must be safe
        // when called from a command or a test.
        $unknown = array_values(array_diff($keys, $permissions->keys()->all()));

        if ($unknown !== []) {
            throw new PermissionException(
                'VALIDATION_ERROR',
                'Unknown permission key',
                ['permissions' => $unknown],
                422,
            );
        }

        // ---- guard 3: you cannot give away what you do not hold.
        if (! $granter->isSuperAdmin()) {
            $ungranted = $this->resolver->missingFrom($granter, $keys);

            if ($ungranted !== []) {
                $this->refuse($granter, $target, $keys, 'escalation', ['ungranted' => $ungranted]);

                throw PermissionException::escalation($ungranted);
            }
        }

        // ---- guard 4: GFT-122 — granting a `high` risk permission needs fresh MFA.
        $highRisk = $permissions->filter->isHighRisk()->keys()->values()->all();

        if ($highRisk !== [] && ! $this->reauth->isSatisfied($granter)) {
            $this->refuse($granter, $target, $keys, 'mfa_required', ['high_risk' => $highRisk]);

            throw PermissionException::mfaRequired([
                'high_risk'  => $highRisk,
                'reauth_via' => 'POST /api/v1/admin/auth/mfa/reauth',
            ]);
        }

        $granted = DB::transaction(function () use ($granter, $target, $keys, $permissions, $scope, $expiresAt, $reason) {
            $granted = [];

            foreach ($keys as $key) {
                $permission = $permissions[$key];

                $existing = DB::table('admin_user_permission')
                    ->where('admin_user_id', $target->id)
                    ->where('permission_id', $permission->id)
                    ->first();

                $effectBefore = $existing->effect ?? null;

                // A grant over an existing deny flips it to allow — that is the intended
                // way to undo a deny, and it is recorded as such in the log.
                DB::table('admin_user_permission')->updateOrInsert(
                    ['admin_user_id' => $target->id, 'permission_id' => $permission->id],
                    [
                        'effect'     => 'allow',
                        'granted_by' => $granter->id,
                        'expires_at' => $expiresAt,
                        'scope'      => $scope === null || $scope === [] ? null : json_encode($scope),
                        'updated_at' => now(),
                        'created_at' => $existing->created_at ?? now(),
                    ]
                );

                PermissionGrantLog::create([
                    'actor_id'      => $granter->id,
                    'target_id'     => $target->id,
                    'permission_id' => $permission->id,
                    'action'        => $effectBefore === null ? 'grant' : ($effectBefore === 'deny' ? 'grant' : 'scope_change'),
                    'effect_before' => $effectBefore,
                    'effect_after'  => 'allow',
                    'scope'         => $scope,
                    'reason'        => $reason,
                    'ip'            => request()->ip(),
                ]);

                $granted[] = $key;
            }

            return $granted;
        });

        // Flush AFTER commit so a rolled-back transaction cannot leave a stale-but-empty
        // cache that would briefly grant more than the database says.
        $this->resolver->flushFor($target->id);

        $this->audit->log(
            $granter,
            'permission.grant',
            'access',
            AdminUser::class,
            $target->id,
            null,
            ['permissions' => $granted, 'scope' => $scope, 'expires_at' => $expiresAt, 'reason' => $reason],
        );

        return [
            'granted'         => $granted,
            'effective_count' => $this->resolver->effectiveFor($target->fresh())->count(),
        ];
    }

    /**
     * A refused attempt is logged. The A.11 criteria require it: "nothing is persisted,
     * and the attempt is logged".
     */
    protected function refuse(AdminUser $granter, AdminUser $target, array $keys, string $reason, array $extra = []): void
    {
        $this->audit->log(
            $granter,
            'permission.grant_refused',
            'access',
            AdminUser::class,
            $target->id,
            null,
            array_merge(['attempted' => $keys, 'refused_because' => $reason], $extra),
        );
    }
}
