<?php

namespace App\Domain\Agency;

use App\Domain\Audit\AuditLogger;
use App\Domain\Economy\RateResolver;
use App\Models\AdminUser;
use App\Models\Agency;
use App\Models\PayoutBatch;
use App\Models\Settlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GFT-086 / GFT-087 — agency settlement batches (A.8d).
 *
 * Three rules, all from docs/02 §15:
 *
 *  1. **Generation is idempotent and replaces only a draft.** Running it twice for the same
 *     period updates the one row; a settlement someone has already raised or approved is
 *     never silently rewritten underneath them.
 *
 *  2. **The splits add back to gross, exactly.** Platform and agency are truncated with
 *     `intdiv` and the hosts absorb the remainder, so no paise is created or lost. The
 *     invariant is asserted before the row is written, not hoped for.
 *
 *  3. **Paying is idempotent.** A batch total is recomputed from its members rather than
 *     incremented, and a settlement already `paid` is skipped — so re-processing a batch
 *     cannot pay twice.
 */
class SettlementService
{
    public function __construct(
        protected HostEarningsRollup $rollup,
        protected RateResolver $rates,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * Build (or rebuild) the draft settlement for an agency and period.
     *
     * @throws AgencyException
     */
    public function generate(Agency $agency, Carbon $from, Carbon $to, AdminUser $actor): Settlement
    {
        if ($to->lt($from)) {
            throw new AgencyException('VALIDATION_ERROR', 'The period ends before it starts.', 422);
        }

        if (! $agency->isApproved()) {
            throw new AgencyException(
                'AGENCY_NOT_APPROVED',
                "{$agency->name} is {$agency->status}. Only an approved agency is settled.",
                422,
            );
        }

        $existing = Settlement::query()
            ->where('agency_id', $agency->id)
            ->whereDate('period_start', $from->toDateString())
            ->first();

        if ($existing !== null && ! $existing->isEditable()) {
            throw new AgencyException(
                'ALREADY_RAISED',
                "That period is already {$existing->status}. Reject it first if the figures need rebuilding.",
                409,
            );
        }

        $perf = $this->rollup->agencyPerformance($agency, $from, $to);

        $gross = $perf['gross_paise'];
        $agencyCut = $perf['agency_cut_paise'];
        $hostCut = $perf['host_cut_paise'];
        // Whatever the other two did not take is the platform's. Deriving it rather than
        // re-applying a rate is what guarantees the three add back to gross.
        $platformCut = $gross - $agencyCut - $hostCut;

        if ($platformCut < 0) {
            throw new AgencyException(
                'SPLIT_IMBALANCE',
                'The agency and host cuts for this period exceed the gross. The rollup needs rebuilding before this can be settled.',
                422,
            );
        }

        $rate = $this->rates->at(RateResolver::DIAMOND_TO_INR, $to);

        $settlement = $existing ?? new Settlement(['agency_id' => $agency->id]);

        $settlement->fill([
            'agency_id'          => $agency->id,
            'period_start'       => $from->toDateString(),
            'period_end'         => $to->toDateString(),
            'gross_diamonds'     => $perf['diamonds'],
            'gross_paise'        => $gross,
            'platform_cut_paise' => $platformCut,
            'agency_cut_paise'   => $agencyCut,
            'host_cut_paise'     => $hostCut,
            // What the platform actually transfers to the agency: their own commission.
            // Host earnings are paid to hosts, not through the agency.
            'net_payable_paise'  => $agencyCut,
            'rate_numerator'     => $rate?->rate_numerator,
            'rate_denominator'   => $rate?->rate_denominator,
            'host_count'         => $perf['earning_hosts'],
            'status'             => Settlement::DRAFT,
            'raised_by'          => $actor->id,
        ]);

        if (! $settlement->splitsBalance()) {
            throw new AgencyException(
                'SPLIT_IMBALANCE',
                'The three cuts do not add back to gross. Refusing to write a settlement that does not balance.',
                500,
            );
        }

        $settlement->save();

        $this->audit->log($actor, 'settlement.generate', 'agency', Settlement::class, $settlement->id, null, [
            'period' => [$from->toDateString(), $to->toDateString()],
            'net_payable_paise' => $settlement->net_payable_paise,
        ]);

        return $settlement->refresh();
    }

    /**
     * @throws AgencyException
     */
    public function raise(Settlement $settlement, ?string $notes, AdminUser $actor): Settlement
    {
        if ($settlement->status !== Settlement::DRAFT) {
            throw new AgencyException('BAD_REQUEST', "That settlement is already {$settlement->status}.", 400);
        }

        $settlement->forceFill([
            'status'    => Settlement::MANAGER_RAISED,
            'raised_by' => $actor->id,
            'notes'     => $notes,
        ])->save();

        $this->audit->log($actor, 'settlement.raise', 'agency', Settlement::class, $settlement->id, null, null);

        return $settlement->refresh();
    }

    /**
     * @throws AgencyException
     */
    public function approve(Settlement $settlement, AdminUser $actor): Settlement
    {
        if (! in_array($settlement->status, [Settlement::DRAFT, Settlement::MANAGER_RAISED], true)) {
            throw new AgencyException('BAD_REQUEST', "That settlement is already {$settlement->status}.", 400);
        }

        // Whoever raised it does not get to approve it. Two-person rule, same as payouts.
        if ($settlement->raised_by === $actor->id && $settlement->status === Settlement::MANAGER_RAISED) {
            throw new AgencyException(
                'SELF_APPROVAL',
                'You raised this settlement, so somebody else has to approve it.',
                403,
            );
        }

        $before = ['status' => $settlement->status];

        $settlement->forceFill([
            'status'      => Settlement::ADMIN_APPROVED,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ])->save();

        $this->audit->log($actor, 'settlement.approve', 'agency', Settlement::class, $settlement->id, $before, [
            'status' => Settlement::ADMIN_APPROVED,
        ]);

        return $settlement->refresh();
    }

    /**
     * @throws AgencyException
     */
    public function reject(Settlement $settlement, string $reason, AdminUser $actor): Settlement
    {
        if ($settlement->isPaid()) {
            throw new AgencyException('BAD_REQUEST', 'That settlement has been paid. Money already moved.', 400);
        }

        $before = ['status' => $settlement->status];

        $settlement->forceFill([
            'status' => Settlement::REJECTED,
            'notes'  => trim($reason),
        ])->save();

        $this->audit->log($actor, 'settlement.reject', 'agency', Settlement::class, $settlement->id, $before, [
            'status' => Settlement::REJECTED, 'reason' => trim($reason),
        ]);

        return $settlement->refresh();
    }

    /**
     * Put approved settlements into a payout batch.
     *
     * @param  array<int, int>  $settlementIds
     *
     * @throws AgencyException
     */
    public function addToBatch(array $settlementIds, AdminUser $actor): PayoutBatch
    {
        return DB::transaction(function () use ($settlementIds, $actor) {
            $settlements = Settlement::query()
                ->whereIn('id', $settlementIds)
                ->lockForUpdate()
                ->get();

            if ($settlements->isEmpty()) {
                throw new AgencyException('VALIDATION_ERROR', 'No settlements were given.', 422);
            }

            foreach ($settlements as $settlement) {
                if ($settlement->status !== Settlement::ADMIN_APPROVED) {
                    throw new AgencyException(
                        'NOT_APPROVED',
                        "Settlement #{$settlement->id} is {$settlement->status}; only approved ones can be batched.",
                        422,
                    );
                }

                if ($settlement->batch_id !== null) {
                    throw new AgencyException(
                        'ALREADY_BATCHED',
                        "Settlement #{$settlement->id} is already in batch #{$settlement->batch_id}.",
                        409,
                    );
                }
            }

            $batch = PayoutBatch::create([
                'batch_number' => 'AGS-'.now()->format('Ymd').'-'.str_pad((string) (PayoutBatch::max('id') + 1), 4, '0', STR_PAD_LEFT),
                'type'         => 'agency_settlement',
                'status'       => 'draft',
                'created_by'   => $actor->id,
            ]);

            Settlement::whereIn('id', $settlements->pluck('id'))->update(['batch_id' => $batch->id]);

            $this->recountBatch($batch);

            $this->audit->log($actor, 'settlement.batch', 'agency', PayoutBatch::class, $batch->id, null, [
                'settlement_ids' => $settlements->pluck('id')->all(),
            ]);

            return $batch->refresh();
        });
    }

    /**
     * Process a batch — A.8d's idempotency requirement.
     *
     * Only settlements not already `paid` are touched, and the batch total is recomputed
     * from its members rather than accumulated. Running this twice therefore reports zero
     * newly paid the second time and leaves every figure identical.
     *
     * @return array{batch: PayoutBatch, newly_paid: int, already_paid: int, total_paise: int}
     *
     * @throws AgencyException
     */
    public function processBatch(PayoutBatch $batch, AdminUser $actor): array
    {
        if ($batch->type !== 'agency_settlement') {
            throw new AgencyException('BAD_REQUEST', 'That is not an agency settlement batch.', 400);
        }

        return DB::transaction(function () use ($batch, $actor) {
            $settlements = Settlement::query()
                ->where('batch_id', $batch->id)
                ->lockForUpdate()
                ->get();

            $alreadyPaid = $settlements->where('status', Settlement::PAID)->count();

            $toPay = $settlements->reject(fn (Settlement $s) => $s->isPaid());

            foreach ($toPay as $settlement) {
                if ($settlement->status !== Settlement::ADMIN_APPROVED) {
                    throw new AgencyException(
                        'NOT_APPROVED',
                        "Settlement #{$settlement->id} is {$settlement->status} and cannot be paid.",
                        422,
                    );
                }

                $settlement->forceFill(['status' => Settlement::PAID, 'paid_at' => now()])->save();
            }

            $batch->forceFill([
                'status'       => 'completed',
                'approved_by'  => $batch->approved_by ?? $actor->id,
                'approved_at'  => $batch->approved_at ?? now(),
                'processed_at' => now(),
            ])->save();

            $this->recountBatch($batch);

            $this->audit->log($actor, 'settlement.batch_process', 'agency', PayoutBatch::class, $batch->id, null, [
                'newly_paid' => $toPay->count(), 'already_paid' => $alreadyPaid,
            ]);

            return [
                'batch'        => $batch->refresh(),
                'newly_paid'   => $toPay->count(),
                'already_paid' => $alreadyPaid,
                'total_paise'  => (int) $batch->total_paise,
            ];
        });
    }

    /**
     * Recompute count and total from the batch's members.
     *
     * Deliberately not `increment()`: a total that is accumulated drifts the moment
     * anything is retried, and a payout total that drifts is the worst possible bug here.
     */
    protected function recountBatch(PayoutBatch $batch): void
    {
        $totals = Settlement::query()
            ->where('batch_id', $batch->id)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(net_payable_paise), 0) AS total')
            ->first();

        $batch->forceFill([
            'count'       => (int) $totals->n,
            'total_paise' => (int) $totals->total,
        ])->save();
    }
}
