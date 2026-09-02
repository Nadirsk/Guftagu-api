<?php

namespace App\Domain\Access\Services;

use App\Models\AdminUser;
use Carbon\CarbonImmutable;

/**
 * GFT-120 — scoped grants: category, agency and shift-window enforcement.
 *
 * Scope shape (docs/02 §2.4):
 *
 *     { "room_categories": [3, 7],
 *       "agencies": [12],
 *       "shift": { "from": "18:00", "to": "02:00", "tz": "Asia/Kolkata" } }
 *
 * An absent or empty scope means unrestricted within that permission. A scope key that is
 * present but not supplied in the call context is treated as a refusal, not a pass —
 * failing open on a missing context value is how a scoped moderator quietly becomes global.
 */
class ScopeGate
{
    public function __construct(protected PermissionResolver $resolver)
    {
    }

    /**
     * @param  array<string, mixed>  $context  e.g. ['room_category' => 5, 'agency_id' => 12]
     */
    public function allows(AdminUser $admin, string $permissionKey, array $context = []): bool
    {
        return $this->reasonToRefuse($admin, $permissionKey, $context) === null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return string|null  null when allowed, otherwise a machine-readable reason
     */
    public function reasonToRefuse(AdminUser $admin, string $permissionKey, array $context = []): ?string
    {
        if (! $this->resolver->has($admin, $permissionKey)) {
            return 'permission_missing';
        }

        // Super Admin is never scoped.
        $scope = $this->resolver->scopeFor($admin, $permissionKey);

        if ($scope === null) {
            return null;
        }

        if (isset($scope['room_categories']) && $scope['room_categories'] !== []) {
            $category = $context['room_category'] ?? null;

            if ($category === null || ! in_array((int) $category, array_map('intval', $scope['room_categories']), true)) {
                return 'out_of_category_scope';
            }
        }

        if (isset($scope['agencies']) && $scope['agencies'] !== []) {
            $agency = $context['agency_id'] ?? null;

            if ($agency === null || ! in_array((int) $agency, array_map('intval', $scope['agencies']), true)) {
                return 'out_of_agency_scope';
            }
        }

        if (isset($scope['shift']) && is_array($scope['shift']) && ! $this->withinShift($scope['shift'])) {
            return 'outside_shift_window';
        }

        return null;
    }

    /**
     * A shift may cross midnight — "18:00" to "02:00" is a valid night shift and must not
     * be read as an empty range.
     *
     * @param  array{from?: string, to?: string, tz?: string}  $shift
     */
    protected function withinShift(array $shift, ?CarbonImmutable $now = null): bool
    {
        $from = $shift['from'] ?? null;
        $to   = $shift['to'] ?? null;

        if ($from === null || $to === null) {
            return true;
        }

        $tz  = $shift['tz'] ?? config('app.timezone', 'UTC');
        $now = ($now ?? CarbonImmutable::now())->setTimezone($tz);

        $minutesNow  = $now->hour * 60 + $now->minute;
        $minutesFrom = $this->toMinutes($from);
        $minutesTo   = $this->toMinutes($to);

        if ($minutesFrom === null || $minutesTo === null) {
            return true;
        }

        if ($minutesFrom === $minutesTo) {
            return true;    // a zero-width window is treated as "all day", not "never"
        }

        return $minutesFrom < $minutesTo
            ? $minutesNow >= $minutesFrom && $minutesNow < $minutesTo
            : $minutesNow >= $minutesFrom || $minutesNow < $minutesTo;   // crosses midnight
    }

    protected function toMinutes(string $time): ?int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m) !== 1) {
            return null;
        }

        $h = (int) $m[1];
        $i = (int) $m[2];

        if ($h > 23 || $i > 59) {
            return null;
        }

        return $h * 60 + $i;
    }
}
