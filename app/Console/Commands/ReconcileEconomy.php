<?php

namespace App\Console\Commands;

use App\Domain\Economy\Reconciler;
use Illuminate\Console\Command;

/**
 * GFT-074 — the nightly reconciliation (A.7d).
 *
 * docs/02 §15 rule 12: "Reconciliation is a job, not a hope. Any discrepancy pages
 * on-call." The engine logs at error level on a mismatch; this exits non-zero as well, so
 * a scheduler or CI step fails loudly rather than reporting success over broken books.
 */
class ReconcileEconomy extends Command
{
    protected $signature = 'economy:reconcile {--quiet-on-pass : Print nothing when everything balances}';

    protected $description = 'Check every wallet against its ledger and report any drift';

    public function handle(Reconciler $reconciler): int
    {
        $report = $reconciler->run();

        if ($report['ok']) {
            if (! $this->option('quiet-on-pass')) {
                foreach ($report['currencies'] as $currency => $result) {
                    $this->info(sprintf(
                        '%-8s %d wallets, %d ledger rows — balanced.',
                        $currency,
                        $result['wallets'],
                        $result['ledger_rows'],
                    ));
                }
            }

            return self::SUCCESS;
        }

        $this->error('Reconciliation failed — a balance has moved without a ledger row.');

        foreach ($report['currencies'] as $currency => $result) {
            foreach ($result['mismatches'] as $mismatch) {
                $this->line(sprintf(
                    '  %-8s user %-6d wallet %-14s ledger %-14s delta %s',
                    $currency,
                    $mismatch['user_id'],
                    number_format($mismatch['wallet_balance']),
                    number_format($mismatch['ledger_total']),
                    number_format($mismatch['delta']),
                ));
            }
        }

        return self::FAILURE;
    }
}
