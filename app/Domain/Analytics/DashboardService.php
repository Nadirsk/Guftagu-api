<?php

namespace App\Domain\Analytics;

use App\Models\DailyStat;
use App\Models\User;
use App\Models\UserKyc;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * GFT-013 / GFT-014 / GFT-015 — everything the dashboard shows.
 *
 * **A.2's NFR: no query here touches a raw transaction table.** Money figures come from
 * `daily_stats`; the live counters come from indexed columns on `users` (which is not a
 * ledger) and are cached briefly. If you are tempted to `SUM(amount)` in this class, put
 * it in StatsRollup instead.
 */
class DashboardService
{
    /** Short enough to feel live, long enough that a room full of admins is one query. */
    public const KPI_TTL = 10;

    /**
     * A.2a — the counters that update while you watch.
     *
     * @return array<string, mixed>
     */
    public function kpis(): array
    {
        return Cache::remember('cache:dashboard:kpis', self::KPI_TTL, function () {
            $now = now();

            $activeToday = User::query()
                ->where('last_active_at', '>=', $now->copy()->startOfDay())
                ->count();

            // DAU/MAU — the "stickiness" ratio. Guarded against divide-by-zero rather
            // than rendering NaN on a fresh install.
            $mau = User::query()->where('last_active_at', '>=', $now->copy()->subDays(30))->count();

            return [
                'users' => [
                    'total'      => User::query()->count(),
                    'active'     => User::query()->where('status', User::STATUS_ACTIVE)->count(),
                    'suspended'  => User::query()->where('status', User::STATUS_SUSPENDED)->count(),
                    'banned'     => User::query()->where('status', User::STATUS_BANNED)->count(),
                    'new_today'  => User::query()->where('created_at', '>=', $now->copy()->startOfDay())->count(),
                    'new_7d'     => User::query()->where('created_at', '>=', $now->copy()->subDays(7))->count(),
                ],
                'engagement' => [
                    'active_today'  => $activeToday,
                    'active_30d'    => $mau,
                    'dau_mau_ratio' => $mau > 0 ? round($activeToday / $mau, 4) : 0.0,
                ],
                'queues' => [
                    'kyc_pending' => UserKyc::query()->where('status', UserKyc::PENDING)->count(),
                ],
                // Named, and honestly zero, so the tile can say "not built yet" instead of
                // implying the platform has no live rooms.
                'rooms' => ['live' => 0, 'available' => false],
                'as_of' => $now->toIso8601ZuluString(),
            ];
        });
    }

    /**
     * A.2b — revenue by stream over a range. Read entirely from the rollup.
     *
     * @return array<string, mixed>
     */
    public function revenue(Carbon $from, Carbon $to, string $granularity = 'day'): array
    {
        $rows = $this->statsFor($from, $to);

        $series = $this->bucket($rows, $granularity, [
            'recharge_coins', 'gifting_coins', 'vip_coins', 'other_coins',
            'admin_credit_coins', 'admin_debit_coins', 'diamonds_earned',
        ]);

        $totals = [
            'recharge'     => (int) $rows->sum('recharge_coins'),
            'gifting'      => (int) $rows->sum('gifting_coins'),
            'vip'          => (int) $rows->sum('vip_coins'),
            'other'        => (int) $rows->sum('other_coins'),
            'admin_credit' => (int) $rows->sum('admin_credit_coins'),
            'admin_debit'  => (int) $rows->sum('admin_debit_coins'),
            'diamonds'     => (int) $rows->sum('diamonds_earned'),
        ];

        return [
            'series'      => $series,
            'totals'      => $totals,
            // A.2b requires the streams to sum to the ledger total for the range, so the
            // figure is returned rather than left for the client to add up differently.
            'coin_total'  => $totals['recharge'] + $totals['gifting'] + $totals['vip'] + $totals['other'],
            'granularity' => $granularity,
            'from'        => $from->toDateString(),
            'to'          => $to->toDateString(),
            // Until payments and gifting land, every stream but the admin ones is zero.
            'streams_live' => ['recharge' => false, 'gifting' => false, 'vip' => false, 'admin' => true],
        ];
    }

    /**
     * A.2c — signups and retention.
     *
     * @return array<string, mixed>
     */
    public function engagement(Carbon $from, Carbon $to, string $granularity = 'day'): array
    {
        $rows = $this->statsFor($from, $to);

        return [
            'series' => $this->bucket($rows, $granularity, [
                'new_users', 'active_users', 'total_users', 'banned_users',
            ]),
            'retention'   => $this->retention(),
            'granularity' => $granularity,
            'from'        => $from->toDateString(),
            'to'          => $to->toDateString(),
        ];
    }

    /**
     * Retention by signup cohort.
     *
     * **This is "still active N days after signing up", not textbook D1/D7/D30.** True
     * D-N retention asks whether someone was active *on* day N, which needs a per-day
     * activity record. The only activity signal that exists today is `users.last_active_at`,
     * so the honest measure is how long after signup a cohort was last seen. The API
     * labels it as such rather than shipping a familiar name over a different number;
     * it becomes exact once the app writes activity days.
     *
     * @return array<string, mixed>
     */
    public function retention(int $weeks = 8): array
    {
        $since = now()->copy()->subWeeks($weeks)->startOfWeek();

        $cohorts = User::query()
            ->selectRaw('YEARWEEK(created_at, 3) AS cohort_key')
            ->selectRaw('MIN(DATE(created_at)) AS cohort_start')
            ->selectRaw('COUNT(*) AS signed_up')
            ->selectRaw('SUM(CASE WHEN last_active_at >= created_at + INTERVAL 1 DAY THEN 1 ELSE 0 END) AS d1')
            ->selectRaw('SUM(CASE WHEN last_active_at >= created_at + INTERVAL 7 DAY THEN 1 ELSE 0 END) AS d7')
            ->selectRaw('SUM(CASE WHEN last_active_at >= created_at + INTERVAL 30 DAY THEN 1 ELSE 0 END) AS d30')
            ->where('created_at', '>=', $since)
            ->groupBy('cohort_key')
            ->orderBy('cohort_start')
            ->get();

        return [
            'measure' => 'still_active_after',
            'note'    => 'Share of each signup cohort last seen at least N days after joining. Exact day-N retention needs per-day activity records, which arrive with the mobile app.',
            'cohorts' => $cohorts->map(function ($row) {
                $signedUp = (int) $row->signed_up;

                return [
                    'cohort'    => (string) $row->cohort_start,
                    'signed_up' => $signedUp,
                    'd1'        => $this->rate((int) $row->d1, $signedUp),
                    'd7'        => $this->rate((int) $row->d7, $signedUp),
                    'd30'       => $this->rate((int) $row->d30, $signedUp),
                ];
            })->all(),
        ];
    }

    /** Rows for the export job, flattened. */
    public function revenueRows(Carbon $from, Carbon $to): Collection
    {
        return $this->statsFor($from, $to)->map(fn (DailyStat $stat) => [
            'date'               => $stat->date->toDateString(),
            'recharge_coins'     => $stat->recharge_coins,
            'gifting_coins'      => $stat->gifting_coins,
            'vip_coins'          => $stat->vip_coins,
            'other_coins'        => $stat->other_coins,
            'admin_credit_coins' => $stat->admin_credit_coins,
            'admin_debit_coins'  => $stat->admin_debit_coins,
            'diamonds_earned'    => $stat->diamonds_earned,
            'new_users'          => $stat->new_users,
            'active_users'       => $stat->active_users,
        ]);
    }

    // ------------------------------------------------------------------ internals

    /** @return Collection<int, DailyStat> */
    protected function statsFor(Carbon $from, Carbon $to): Collection
    {
        return DailyStat::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get();
    }

    /**
     * Collapses daily rows into day / week / month buckets.
     *
     * `total_users` is a running total, so bucketing it by SUM would be nonsense — it
     * takes the last value in the bucket instead.
     *
     * @param  Collection<int, DailyStat>  $rows
     * @param  array<int, string>  $columns
     * @return array<int, array<string, mixed>>
     */
    protected function bucket(Collection $rows, string $granularity, array $columns): array
    {
        $cumulative = ['total_users', 'banned_users'];

        $grouped = $rows->groupBy(function (DailyStat $stat) use ($granularity) {
            return match ($granularity) {
                'month' => $stat->date->format('Y-m'),
                'week'  => $stat->date->copy()->startOfWeek()->toDateString(),
                default => $stat->date->toDateString(),
            };
        });

        return $grouped->map(function (Collection $bucket, string $label) use ($columns, $cumulative) {
            $point = ['label' => $label];

            foreach ($columns as $column) {
                $point[$column] = in_array($column, $cumulative, true)
                    ? (int) $bucket->last()->{$column}
                    : (int) $bucket->sum($column);
            }

            return $point;
        })->values()->all();
    }

    protected function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole, 4) : 0.0;
    }

    /** Proves the NFR rather than asserting it — used by the test. */
    public static function ledgerTables(): array
    {
        return ['coin_transactions', 'diamond_transactions'];
    }
}
