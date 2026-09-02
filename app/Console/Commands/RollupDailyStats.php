<?php

namespace App\Console\Commands;

use App\Domain\Analytics\StatsRollup;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * GFT-018 — the nightly rollup.
 *
 *   php artisan stats:rollup              # yesterday and today
 *   php artisan stats:rollup --days=90    # backfill
 *   php artisan stats:rollup --from=2026-08-01 --to=2026-08-31
 */
class RollupDailyStats extends Command
{
    protected $signature = 'stats:rollup
        {--days= : Roll up this many days back from today}
        {--from= : Start date (YYYY-MM-DD)}
        {--to= : End date (YYYY-MM-DD), defaults to today}';

    protected $description = 'Recompute the daily_stats rollup the dashboard reads from';

    public function handle(StatsRollup $rollup): int
    {
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : now();

        if ($this->option('days')) {
            $from = $to->copy()->subDays(max(0, (int) $this->option('days') - 1));
        } elseif ($this->option('from')) {
            $from = Carbon::parse($this->option('from'));
        } else {
            // Yesterday as well as today: a day is only final once it is over, and the
            // partial "today" row gets corrected on the next run.
            $from = $to->copy()->subDay();
        }

        if ($from->gt($to)) {
            $this->error('The start date is after the end date.');

            return self::FAILURE;
        }

        $this->info("Rolling up {$from->toDateString()} → {$to->toDateString()}…");

        $written = $rollup->run($from, $to);

        $this->info("Done — {$written} ".str('day')->plural($written).' written.');

        return self::SUCCESS;
    }
}
