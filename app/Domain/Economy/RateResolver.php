<?php

namespace App\Domain\Economy;

use App\Models\ConversionRate;
use Illuminate\Support\Carbon;

/**
 * GFT-066 — effective-dated conversion rates, in rational arithmetic (A.7a).
 *
 * Two rules do the work here:
 *
 *  1. **A rate is a fraction, not a float.** `diamond_to_inr = 1/2` is exact; `0.5` is
 *     exact only by luck, and `1/3` is not. Conversion multiplies then divides with
 *     `intdiv`, so the result is a whole number of paise with a documented rounding
 *     direction rather than whatever the FPU produced.
 *
 *  2. **A rate is resolved for a moment in time.** Setting a rate effective tomorrow
 *     leaves today's conversions alone. Callers that persist a converted amount must also
 *     persist the rate they used — see `Withdrawal`, which stores the numerator and
 *     denominator on the row so an approval next week still settles at today's price.
 */
class RateResolver
{
    public const COIN_TO_DIAMOND = 'coin_to_diamond';
    public const DIAMOND_TO_INR = 'diamond_to_inr';

    /** The rate for `$key` in force at `$at`, or null when none has been set. */
    public function at(string $key, ?Carbon $at = null): ?ConversionRate
    {
        $moment = $at ?? now();

        return ConversionRate::query()
            ->where('key', $key)
            ->where('effective_from', '<=', $moment)
            ->where(function ($q) use ($moment) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $moment);
            })
            // If two rows somehow overlap, the later start wins — the most recent
            // decision is the operative one.
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Apply a rate to an integer amount.
     *
     * Rounds **down**, deliberately: when the platform is paying out, a fraction of a
     * paise should not become a paise in the recipient's favour on every single request.
     * The direction is a policy choice, so it is stated rather than inherited from a
     * language default.
     */
    public function convert(int $amount, ConversionRate $rate): int
    {
        if ($rate->rate_denominator === 0) {
            throw new EconomyException('INVALID_RATE', 'That rate has a zero denominator.', 422);
        }

        return intdiv($amount * $rate->rate_numerator, $rate->rate_denominator);
    }

    /**
     * @throws EconomyException when no rate has been configured
     */
    public function require(string $key, ?Carbon $at = null): ConversionRate
    {
        $rate = $this->at($key, $at);

        if ($rate === null) {
            throw new EconomyException(
                'RATE_NOT_SET',
                "No {$key} rate is in force. Set one before this operation can be priced.",
                422,
            );
        }

        return $rate;
    }

    /**
     * Open a new rate, closing whatever is currently in force.
     *
     * Existing rows are never edited — an edit would silently re-price history. The
     * outgoing row's `effective_to` is set to the incoming row's start, so the timeline
     * has no gap and no overlap.
     *
     * @throws EconomyException
     */
    public function set(
        string $key,
        int $numerator,
        int $denominator,
        ?Carbon $from = null,
        ?int $setBy = null,
        ?string $note = null,
    ): ConversionRate {
        if ($numerator < 1 || $denominator < 1) {
            throw new EconomyException('INVALID_RATE', 'A rate must be a positive fraction.', 422);
        }

        $from = $from ?? now();

        // Closing a row that starts after the new one would corrupt the timeline.
        $future = ConversionRate::query()
            ->where('key', $key)
            ->where('effective_from', '>', $from)
            ->exists();

        if ($future) {
            throw new EconomyException(
                'RATE_CONFLICT',
                'A later rate is already scheduled for this key. Remove it before backdating a new one.',
                422,
            );
        }

        $current = $this->at($key, $from);

        if ($current !== null) {
            $current->forceFill(['effective_to' => $from])->save();
        }

        return ConversionRate::create([
            'key'              => $key,
            'rate_numerator'   => $numerator,
            'rate_denominator' => $denominator,
            'effective_from'   => $from,
            'effective_to'     => null,
            'set_by'           => $setBy,
            'note'             => $note,
        ]);
    }

    /**
     * The full timeline for a key, newest first — what the panel's effective-date view
     * renders (GFT-075).
     *
     * @return \Illuminate\Support\Collection<int, ConversionRate>
     */
    public function timeline(string $key): \Illuminate\Support\Collection
    {
        return ConversionRate::query()
            ->with('setBy:id,name')
            ->where('key', $key)
            ->orderByDesc('effective_from')
            ->get();
    }
}
