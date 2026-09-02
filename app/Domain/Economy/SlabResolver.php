<?php

namespace App\Domain\Economy;

use App\Models\CommissionSlab;
use Illuminate\Support\Carbon;

/**
 * Picks the commission slab that covers a value, for a moment in time (A.7c → A.8b/d).
 *
 * Slabs are effective-dated exactly like conversion rates, and for the same reason: an
 * agency settled in October must be settled on October's terms even if the contract
 * changed in November. Callers that persist a settled amount also persist the basis points
 * they used, so a re-read never silently re-prices history.
 *
 * `max_value` NULL is the open-ended top slab. A value that falls in no slab returns null
 * rather than defaulting to zero — "no slab covers this" and "the rate is 0%" are
 * different facts, and quietly conflating them is how an agency gets paid nothing.
 */
class SlabResolver
{
    /**
     * The slab covering `$value` for `$appliesTo`/`$metric` at `$at`.
     *
     * An agency-specific slab beats a global one: a negotiated rate exists precisely to
     * override the default.
     */
    public function for(
        string $appliesTo,
        string $metric,
        int $value,
        ?int $agencyId = null,
        ?Carbon $at = null,
    ): ?CommissionSlab {
        $moment = $at ?? now();

        return CommissionSlab::query()
            ->where('applies_to', $appliesTo)
            ->where('metric', $metric)
            ->where('min_value', '<=', $value)
            ->where(fn ($q) => $q->whereNull('max_value')->orWhere('max_value', '>=', $value))
            ->where('effective_from', '<=', $moment)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $moment))
            ->where(fn ($q) => $q->whereNull('agency_id')->orWhere('agency_id', $agencyId))
            // A negotiated rate wins over the default; if two still overlap, the later
            // decision is the operative one.
            ->orderByRaw('agency_id IS NULL')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /** Basis points for that slab, or `$default` when nothing covers the value. */
    public function basisPoints(
        string $appliesTo,
        string $metric,
        int $value,
        ?int $agencyId = null,
        ?Carbon $at = null,
        int $default = 0,
    ): int {
        return $this->for($appliesTo, $metric, $value, $agencyId, $at)?->percentage_bp ?? $default;
    }

    /**
     * Apply basis points to an integer amount.
     *
     * `intdiv` truncates, which means the platform never rounds a fraction of a paisa in
     * its own favour — the remainder falls to whoever is paid last. Which party that is
     * matters, so `SettlementService` states it explicitly rather than relying on the
     * order the cuts happen to be computed in.
     */
    public function apply(int $amountPaise, int $basisPoints): int
    {
        return intdiv($amountPaise * $basisPoints, 10000);
    }
}
