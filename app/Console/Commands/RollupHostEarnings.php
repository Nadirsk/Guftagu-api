<?php

namespace App\Console\Commands;

use App\Domain\Agency\HostEarningsRollup;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * GFT-084 — nightly `host_earnings` rebuild (A.8c).
 *
 * Recomputes rather than accumulates, so a missed night is fixed by running it again for
 * that date and a late ledger credit is picked up on the next pass.
 */
class RollupHostEarnings extends Command
{
    protected $signature = 'hosts:rollup-earnings
                            {--date= : A single day (Y-m-d). Defaults to yesterday.}
                            {--from= : Start of a range to rebuild}
                            {--to= : End of a range to rebuild}';

    protected $description = 'Rebuild the daily host earnings rollup from the diamond ledger';

    public function handle(HostEarningsRollup $rollup): int
    {
        if ($this->option('from') !== null) {
            $from = Carbon::parse($this->option('from'));
            $to = Carbon::parse($this->option('to') ?? $this->option('from'));

            if ($to->lt($from)) {
                $this->error('--to is before --from.');

                return self::FAILURE;
            }

            $results = $rollup->forRange($from, $to);

            $this->table(
                ['Date', 'Hosts', 'Diamonds', 'Net (paise)', 'Priced'],
                array_map(fn ($r) => [
                    $r['date'], $r['hosts'], $r['diamonds'], $r['net_paise'],
                    $r['priced'] ? 'yes' : 'NO RATE',
                ], $results),
            );

            $unpriced = array_filter($results, fn ($r) => ! $r['priced'] && $r['diamonds'] > 0);

            if ($unpriced !== []) {
                $this->warn(sprintf(
                    '%d %s recorded diamonds with no diamond-to-INR rate in force, so every money column is zero for them.',
                    count($unpriced),
                    str('day')->plural(count($unpriced)),
                ));
            }

            return self::SUCCESS;
        }

        $result = $rollup->forDate($this->option('date') === null ? null : Carbon::parse($this->option('date')));

        $this->info(sprintf(
            '%s — %d %s, %s diamonds, %s paise net.',
            $result['date'],
            $result['hosts'],
            str('host')->plural($result['hosts']),
            number_format($result['diamonds']),
            number_format($result['net_paise']),
        ));

        if (! $result['priced'] && $result['diamonds'] > 0) {
            $this->warn('No diamond-to-INR rate covers that day, so the money columns are all zero. Set a rate effective from before it and run this again.');
        }

        return self::SUCCESS;
    }
}
