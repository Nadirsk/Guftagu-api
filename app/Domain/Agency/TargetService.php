<?php

namespace App\Domain\Agency;

use App\Domain\Audit\AuditLogger;
use App\Domain\Economy\RateResolver;
use App\Domain\Economy\SlabResolver;
use App\Models\AdminUser;
use App\Models\Host;
use App\Models\HostTarget;
use Illuminate\Support\Carbon;

/**
 * GFT-082 / GFT-083 — host targets and the incentive they earn (A.8b).
 *
 * **Progress is derived, the settled result is stored.** While a period is open,
 * `progress()` reads `host_earnings` live, so the panel always agrees with the ledger even
 * if last night's job did not run. Once the period ends, `evaluate()` freezes the numbers
 * and the incentive onto the row — because the amount someone is owed must not move
 * afterwards, no matter what a rate or slab does next.
 *
 * That is the same division used everywhere else here: derive what is still changing,
 * freeze what has been decided.
 */
class TargetService
{
    public function __construct(
        protected HostEarningsRollup $rollup,
        protected SlabResolver $slabs,
        protected RateResolver $rates,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * @throws AgencyException
     */
    public function create(Host $host, array $data, AdminUser $actor): HostTarget
    {
        $start = Carbon::parse($data['period_start'])->startOfDay();
        $end = Carbon::parse($data['period_end'])->startOfDay();

        if ($end->lt($start)) {
            throw new AgencyException('VALIDATION_ERROR', 'The period ends before it starts.', 422);
        }

        if (HostTarget::where('host_id', $host->id)->whereDate('period_start', $start)->exists()) {
            throw new AgencyException('BAD_REQUEST', 'This host already has a target starting that day.', 400);
        }

        // Overlapping targets would make achievement ambiguous — the same diamonds would
        // count towards two incentives.
        $overlap = HostTarget::query()
            ->where('host_id', $host->id)
            ->where('status', '!=', HostTarget::CANCELLED)
            ->whereDate('period_start', '<=', $end)
            ->whereDate('period_end', '>=', $start)
            ->first();

        if ($overlap !== null) {
            throw new AgencyException(
                'PERIOD_OVERLAP',
                "That overlaps the target running {$overlap->period_start->toDateString()} to {$overlap->period_end->toDateString()}.",
                422,
            );
        }

        $target = HostTarget::create([
            'host_id'         => $host->id,
            'period_start'    => $start,
            'period_end'      => $end,
            'target_diamonds' => (int) ($data['target_diamonds'] ?? 0),
            'target_hours'    => (int) ($data['target_hours'] ?? 0),
            'target_days'     => (int) ($data['target_days'] ?? 0),
            'status'          => HostTarget::ACTIVE,
            'created_by'      => $actor->id,
        ]);

        $this->audit->log($actor, 'host_target.create', 'agency', HostTarget::class, $target->id, null, $data);

        return $target;
    }

    /**
     * Live progress against a target.
     *
     * Achievement is measured on the metrics that were actually set — a target of 100,000
     * diamonds and no hours target is 100% about diamonds. Averaging in a zero for an unset
     * metric would report 50% for a host who hit their number exactly.
     *
     * @return array<string, mixed>
     */
    public function progress(HostTarget $target): array
    {
        $totals = $this->rollup->totals(
            $target->host,
            $target->period_start->copy(),
            $target->period_end->copy(),
        );

        $parts = [];

        if ($target->target_diamonds > 0) {
            $parts['diamonds'] = $this->pct($totals['diamonds'], $target->target_diamonds);
        }

        if ($target->target_hours > 0) {
            $parts['hours'] = $this->pct($totals['room_hours'], $target->target_hours);
        }

        if ($target->target_days > 0) {
            $parts['days'] = $this->pct($totals['active_days'], $target->target_days);
        }

        // No metric set at all is a target nobody can hit or miss; say so rather than
        // reporting 0% or 100%.
        $overall = $parts === [] ? null : (int) round(array_sum($parts) / count($parts));

        return [
            'achieved_diamonds' => $totals['diamonds'],
            'achieved_hours'    => $totals['room_hours'],
            'achieved_days'     => $totals['active_days'],
            'per_metric'        => $parts,
            'achievement_pct'   => $overall,
            'net_paise'         => $totals['net_paise'],
            'is_live'           => $target->isOpen(),
            'note'              => $target->target_hours > 0
                ? 'Room hours stay at zero until room session tracking lands with D.3, so an hours target cannot be met yet.'
                : null,
        ];
    }

    /**
     * Freeze the result and compute the incentive (A.8b).
     *
     * The incentive slab is looked up **on achievement percentage**, at the period's end
     * date — so a slab table edited next month does not repay last month.
     *
     * @throws AgencyException
     */
    public function evaluate(HostTarget $target, ?AdminUser $actor = null): HostTarget
    {
        if ($target->evaluated_at !== null) {
            throw new AgencyException('BAD_REQUEST', 'That target has already been evaluated.', 400);
        }

        if ($target->status === HostTarget::CANCELLED) {
            throw new AgencyException('BAD_REQUEST', 'A cancelled target is not evaluated.', 400);
        }

        $progress = $this->progress($target);
        $pct = $progress['achievement_pct'] ?? 0;

        $at = $target->period_end->copy()->endOfDay();

        // The slab is keyed on the achievement percentage, so a 75% month lands in the
        // band covering 75.
        $slab = $this->slabs->for('host', 'diamonds_earned', $pct, $target->host->agency_id, $at);

        $incentiveBp = $slab?->percentage_bp ?? 0;
        $incentive = $this->slabs->apply($progress['net_paise'], $incentiveBp);

        $target->forceFill([
            'achieved_diamonds' => $progress['achieved_diamonds'],
            'achieved_hours'    => $progress['achieved_hours'],
            'achieved_days'     => $progress['achieved_days'],
            'achievement_pct'   => $pct,
            'incentive_bp'      => $incentiveBp,
            'incentive_paise'   => $incentive,
            'status'            => $pct >= 100 ? HostTarget::ACHIEVED : HostTarget::MISSED,
            'evaluated_at'      => now(),
        ])->save();

        $this->audit->log($actor, 'host_target.evaluate', 'agency', HostTarget::class, $target->id, null, [
            'achievement_pct' => $pct, 'incentive_paise' => $incentive, 'incentive_bp' => $incentiveBp,
        ]);

        return $target->refresh();
    }

    /**
     * @throws AgencyException
     */
    public function cancel(HostTarget $target, AdminUser $actor): HostTarget
    {
        if ($target->evaluated_at !== null) {
            throw new AgencyException(
                'BAD_REQUEST',
                'That target has been evaluated — an incentive was computed from it, so it cannot be cancelled.',
                400,
            );
        }

        $target->forceFill(['status' => HostTarget::CANCELLED])->save();

        $this->audit->log($actor, 'host_target.cancel', 'agency', HostTarget::class, $target->id, null, null);

        return $target->refresh();
    }

    /** Whole percent, capped for display sanity at 999 so a chart bar stays a bar. */
    protected function pct(int $achieved, int $target): int
    {
        if ($target <= 0) {
            return 0;
        }

        return min(999, (int) floor($achieved * 100 / $target));
    }
}
