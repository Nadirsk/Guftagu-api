<?php

namespace App\Domain\Moderation;

use App\Domain\Access\Services\PermissionCache;
use App\Domain\Access\Services\PermissionResolver;
use App\Models\AdminUser;

/**
 * GFT-171 / C.4b — how long a given moderator is allowed to ban somebody for.
 *
 * > "Given a Moderator granted `ban_temp` with a 72-hour policy cap, when they attempt a
 * > 30-day ban, then it is rejected `422` naming the cap."
 *
 * The cap lives on the **grant**, in the same `scope` JSON that already carries agency and
 * category limits, as `max_ban_hours`. That is deliberate: it is a property of the
 * delegation, not of the role, so a Super Admin can hand one moderator a 72-hour ceiling
 * and another a 30-day one without inventing a new role for each. A grant with no
 * `max_ban_hours` is uncapped, matching how every other scope key behaves.
 *
 * Two things it does **not** do:
 *
 *  - It does not cap `ban_permanent`. A permanent ban is a different permission with its
 *    own gate; silently reinterpreting it as a long temp ban would be worse than refusing.
 *  - It does not cap a Super Admin. Same rule as scoping.
 */
class BanPolicy
{
    public const SCOPE_KEY = 'max_ban_hours';

    protected const TTL = PermissionCache::TTL;

    public function __construct(protected PermissionResolver $resolver)
    {
    }

    /**
     * The tightest cap across this admin's live grants, in hours, or null for uncapped.
     *
     * **The tightest, not the loosest.** Two grants each carrying a ceiling means two
     * people each set a limit they expected to hold; taking the maximum would let the
     * looser one silently override the stricter, which is the opposite of what either
     * person intended.
     */
    public function maxBanHours(AdminUser $admin): ?int
    {
        if ($admin->isSuperAdmin()) {
            return null;
        }

        // The same per-admin namespace the permission set lives in, so revoking or
        // re-scoping a grant clears the cap with it. A stale 30-day ceiling outliving the
        // grant that set it is exactly the failure the shared namespace prevents.
        return app(PermissionCache::class)->remember(
            $admin->id,
            'ban_cap',
            function () use ($admin) {
                $caps = [];

                foreach ($admin->directGrants()->allow()->notExpired()->get() as $grant) {
                    $scope = $grant->pivot->scope;
                    $scope = is_array($scope) ? $scope : json_decode((string) $scope, true);

                    if (is_array($scope) && isset($scope[self::SCOPE_KEY]) && (int) $scope[self::SCOPE_KEY] > 0) {
                        $caps[] = (int) $scope[self::SCOPE_KEY];
                    }
                }

                return $caps === [] ? null : min($caps);
            },
        );
    }

    /**
     * @throws ModerationException
     */
    public function guardDuration(AdminUser $admin, ?int $minutes): void
    {
        $cap = $this->maxBanHours($admin);

        if ($cap === null || $minutes === null) {
            return;
        }

        if ($minutes <= $cap * 60) {
            return;
        }

        throw new ModerationException(
            'BAN_DURATION_CAPPED',
            sprintf(
                'Your ban limit is %s. A %s ban needs somebody with a higher limit — escalate the report instead.',
                // Named in the unit it was configured in. The cap is set as hours, so
                // rendering 72 of them as "3 days" sends the operator hunting for a
                // setting that says 3 days, which does not exist.
                $this->capLabel($cap),
                $this->readable($minutes),
            ),
            422,
        );
    }

    /**
     * The cap, in the unit it was configured in.
     *
     * A day count is added in brackets past 48 hours, because "168 hours" is hard to
     * picture and "168 hours (7 days)" is not.
     */
    public function capLabel(int $hours): string
    {
        $label = $hours.' '.str('hour')->plural($hours);

        if ($hours >= 48 && $hours % 24 === 0) {
            $days = intdiv($hours, 24);
            $label .= " ({$days} ".str('day')->plural($days).')';
        }

        return $label;
    }

    /** "3 days" reads better than "43200 minutes" for a duration somebody just typed. */
    public function readable(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' '.str('minute')->plural($minutes);
        }

        if ($minutes < 1440) {
            $hours = intdiv($minutes, 60);

            return $hours.' '.str('hour')->plural($hours);
        }

        $days = intdiv($minutes, 1440);

        return $days.' '.str('day')->plural($days);
    }

    /**
     * What to tell the panel, so the duration picker can grey out what would be refused
     * rather than offering it and then failing.
     *
     * @return array<string, mixed>
     */
    public function describe(AdminUser $admin): array
    {
        $cap = $this->maxBanHours($admin);

        return [
            'max_ban_hours'   => $cap,
            'max_ban_minutes' => $cap === null ? null : $cap * 60,
            'note' => $cap === null
                ? null
                : "Your ban limit is {$this->capLabel($cap)}. Anything longer has to be escalated.",
        ];
    }
}
