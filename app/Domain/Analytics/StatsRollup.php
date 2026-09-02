<?php

namespace App\Domain\Analytics;

use App\Models\DailyStat;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GFT-018 — turns the raw tables into one row per day.
 *
 * This is the *only* place allowed to scan the ledgers. Everything the dashboard shows is
 * read back out of `daily_stats`, which is what makes A.2's NFR ("no query scans a raw
 * transaction table") true rather than aspirational.
 *
 * Idempotent by date: re-running a day recomputes and overwrites it, so a failed or
 * partial run is fixed by running it again.
 */
class StatsRollup
{
    /** Ledger `type` values grouped into the revenue streams A.2b reports separately. */
    protected const STREAMS = [
        'recharge_coins' => ['recharge'],
        'gifting_coins'  => ['gift_sent'],
        'vip_coins'      => ['vip_purchase'],
    ];

    /** @return int the number of days written */
    public function run(Carbon $from, Carbon $to): int
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        $written = 0;

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $this->rollupDay($date);
            $written++;
        }

        return $written;
    }

    public function rollupDay(Carbon $date): DailyStat
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();

        $coins = $this->coinTotalsFor($dayStart, $dayEnd);

        return DailyStat::updateOrCreate(
            ['date' => $dayStart->toDateString()],
            [
                'new_users' => User::withTrashed()
                    ->whereBetween('created_at', [$dayStart, $dayEnd])->count(),

                'active_users' => User::withTrashed()
                    ->whereBetween('last_active_at', [$dayStart, $dayEnd])->count(),

                // A running total as at the end of that day, so the series is a real
                // cumulative curve rather than a snapshot repeated backwards.
                'total_users' => User::withTrashed()->where('created_at', '<=', $dayEnd)->count(),

                'banned_users' => User::withTrashed()
                    ->where('status', User::STATUS_BANNED)
                    ->where('created_at', '<=', $dayEnd)->count(),

                'recharge_coins'     => $coins['recharge_coins'],
                'gifting_coins'      => $coins['gifting_coins'],
                'vip_coins'          => $coins['vip_coins'],
                'other_coins'        => $coins['other_coins'],
                'admin_credit_coins' => $coins['admin_credit_coins'],
                'admin_debit_coins'  => $coins['admin_debit_coins'],

                'diamonds_earned' => (int) DB::table('diamond_transactions')
                    ->whereBetween('created_at', [$dayStart, $dayEnd])
                    ->where('direction', 'credit')
                    ->sum('amount'),

                // Rooms land with A.4; zero here keeps the shape stable until then.
                'rooms_opened'    => 0,
                'peak_live_rooms' => 0,

                'computed_at' => now(),
            ],
        );
    }

    /**
     * One grouped pass over the coin ledger for the day, rather than a query per stream.
     *
     * @return array<string, int>
     */
    protected function coinTotalsFor(Carbon $dayStart, Carbon $dayEnd): array
    {
        $rows = DB::table('coin_transactions')
            ->selectRaw('type, direction, SUM(amount) AS total')
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->groupBy('type', 'direction')
            ->get();

        $totals = [
            'recharge_coins'     => 0,
            'gifting_coins'      => 0,
            'vip_coins'          => 0,
            'other_coins'        => 0,
            'admin_credit_coins' => 0,
            'admin_debit_coins'  => 0,
        ];

        foreach ($rows as $row) {
            $amount = (int) $row->total;

            if ($row->type === 'admin_credit') {
                $totals['admin_credit_coins'] += $amount;

                continue;
            }

            if ($row->type === 'admin_debit') {
                $totals['admin_debit_coins'] += $amount;

                continue;
            }

            $bucket = null;

            foreach (self::STREAMS as $column => $types) {
                if (in_array($row->type, $types, true)) {
                    $bucket = $column;
                    break;
                }
            }

            // Anything not yet classified still lands somewhere, so the streams always sum
            // to the ledger total for the range — A.2b depends on that being exactly true.
            $totals[$bucket ?? 'other_coins'] += $amount;
        }

        return $totals;
    }
}
