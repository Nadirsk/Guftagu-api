<?php

namespace App\Domain\Access\Services;

use App\Domain\Access\Exceptions\ScopeException;
use App\Models\AdminUser;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Facades\Cache;

/**
 * GFT-129 / GFT-147 — the query-level half of scoping (B.1a, B.5a).
 *
 * `ScopeGate` answers **"may this admin act on agency 12?"** — one subject, one decision.
 * That is the right shape for a mutation and the wrong shape for a list: asking it per row
 * would be N queries and would still not stop a `COUNT` or a `SUM` from including rows the
 * admin may not see. This is the other half: **"which agencies may they see at all?"**,
 * answered once and pushed into SQL.
 *
 * B.1a is explicit that the filter must be server-side — "not by hiding rows in the UI" —
 * and that a direct API call for another agency's id returns 403. Both halves are needed
 * for that: `agencyIds()` narrows the list, `guardAgency()` refuses the direct call.
 *
 * **Scope is a property of the person, not of one permission.** The union of `agencies`
 * across every live direct grant is taken as the admin's scope, and it then constrains
 * every agency-shaped query. The alternative — scope stored per permission key — means an
 * operator who scopes `agency.view` and forgets `hosts.view` has quietly given away every
 * host on the platform. Nobody administering this will hold that distinction in their head,
 * so the safe reading is the one implemented.
 *
 * **A scope narrows a permission even when a role also grants it unscoped.** A Manager
 * holds `agency.view` from their role baseline; giving them a scoped direct grant is how
 * somebody says "only agency 12". Letting the role win would make the scope decorative.
 */
class ScopeFilter
{
    protected const TTL = 300;

    public function __construct(protected PermissionResolver $resolver)
    {
    }

    /**
     * Agencies this admin may see, or **null for unrestricted**.
     *
     * Null and `[]` mean opposite things and are never conflated: null is "no restriction",
     * an empty array would be "may see nothing". A scope that resolves to an empty list is
     * returned as `[]` so callers produce an empty result rather than the whole table.
     *
     * @return array<int, int>|null
     */
    public function agencyIds(AdminUser $admin): ?array
    {
        return $this->dimension($admin, 'agencies');
    }

    /**
     * Room categories this admin may see, or null for unrestricted.
     *
     * @return array<int, int>|null
     */
    public function roomCategoryIds(AdminUser $admin): ?array
    {
        return $this->dimension($admin, 'room_categories');
    }

    public function isScoped(AdminUser $admin): bool
    {
        return $this->agencyIds($admin) !== null || $this->roomCategoryIds($admin) !== null;
    }

    /**
     * Constrain a query to the admin's agencies.
     *
     * `$column` is the fully-qualified agency column on the query being narrowed.
     * `$includeUnassigned` decides what happens to rows with a null agency — a host with no
     * agency belongs to nobody, so a scoped Manager should not see them, but an unscoped
     * admin must.
     */
    public function applyAgency(
        BuilderContract $query,
        AdminUser $admin,
        string $column = 'agency_id',
        bool $includeUnassigned = false,
    ): BuilderContract {
        $ids = $this->agencyIds($admin);

        if ($ids === null) {
            return $query;
        }

        if ($ids === []) {
            // Scoped to nothing. `whereRaw('0=1')` rather than returning early, so the
            // caller still gets a real builder it can paginate.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (BuilderContract $q) use ($column, $ids, $includeUnassigned) {
            $q->whereIn($column, $ids);

            if ($includeUnassigned) {
                $q->orWhereNull($column);
            }
        });
    }

    public function applyRoomCategory(
        BuilderContract $query,
        AdminUser $admin,
        string $column = 'category_id',
    ): BuilderContract {
        $ids = $this->roomCategoryIds($admin);

        if ($ids === null) {
            return $query;
        }

        return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn($column, $ids);
    }

    /**
     * The 403 half of B.1a: a direct API call for an out-of-scope id.
     *
     * A null `$agencyId` on a scoped admin is a refusal, not a pass. An unassigned host or
     * a platform-wide record is outside every agency scope by definition, and failing open
     * on a missing value is exactly how a scoped Manager quietly becomes global.
     *
     * @throws ScopeException
     */
    public function guardAgency(AdminUser $admin, ?int $agencyId, string $what = 'record'): void
    {
        $ids = $this->agencyIds($admin);

        if ($ids === null) {
            return;
        }

        if ($agencyId !== null && in_array($agencyId, $ids, true)) {
            return;
        }

        throw new ScopeException(
            'OUT_OF_SCOPE',
            $agencyId === null
                ? "That {$what} is not attached to any agency, and your access is limited to specific agencies."
                : "That {$what} belongs to an agency outside your assigned scope.",
        );
    }

    /**
     * @throws ScopeException
     */
    public function guardRoomCategory(AdminUser $admin, ?int $categoryId, string $what = 'room'): void
    {
        $ids = $this->roomCategoryIds($admin);

        if ($ids === null) {
            return;
        }

        if ($categoryId !== null && in_array($categoryId, $ids, true)) {
            return;
        }

        throw new ScopeException(
            'OUT_OF_SCOPE',
            "That {$what} is in a category outside your assigned scope.",
        );
    }

    /**
     * What to tell the panel, so a scoped view can say why it is showing less.
     *
     * A Manager looking at three agencies when they expected thirty should be told it is a
     * scope and not an outage.
     *
     * @return array<string, mixed>|null
     */
    public function describe(AdminUser $admin): ?array
    {
        $agencies = $this->agencyIds($admin);
        $categories = $this->roomCategoryIds($admin);

        if ($agencies === null && $categories === null) {
            return null;
        }

        return [
            'agencies'        => $agencies,
            'room_categories' => $categories,
            'note' => 'You are seeing a scoped view. Totals, counts and exports cover only what is assigned to you.',
        ];
    }

    /**
     * Union every live direct grant's values for one scope dimension.
     *
     * @return array<int, int>|null
     */
    protected function dimension(AdminUser $admin, string $key): ?array
    {
        // Super Admin is never scoped — the same rule PermissionResolver applies.
        if ($admin->isSuperAdmin()) {
            return null;
        }

        // Tagged with the same tag PermissionResolver uses, so its `flushFor()` clears the
        // scope too. Five call sites already flush permissions after a grant changes;
        // adding a sixth thing they each have to remember is how a revoked scope survives
        // in cache for five minutes and lets somebody see an agency they were just
        // removed from.
        return Cache::tags(["perm:{$admin->id}"])->remember(
            "scope:{$key}:{$admin->id}",
            self::TTL,
            function () use ($admin, $key) {
                $grants = $admin->directGrants()->allow()->notExpired()->get();

                $found = false;
                $values = [];

                foreach ($grants as $grant) {
                    $scope = $grant->pivot->scope;
                    $scope = is_array($scope) ? $scope : json_decode((string) $scope, true);

                    if (! is_array($scope) || ! isset($scope[$key]) || ! is_array($scope[$key])) {
                        continue;
                    }

                    // A grant carrying an *empty* list for this dimension is treated as no
                    // restriction on it, matching PermissionResolver::decodeScope.
                    if ($scope[$key] === []) {
                        continue;
                    }

                    $found = true;
                    $values = array_merge($values, array_map('intval', $scope[$key]));
                }

                return $found ? array_values(array_unique($values)) : null;
            },
        );
    }

    /**
     * Only needed to clear a scope without touching permissions. The usual path is
     * `PermissionResolver::flushFor()`, which clears both through the shared tag.
     */
    public function flushFor(int $adminId): void
    {
        Cache::tags(["perm:{$adminId}"])->flush();
    }
}
