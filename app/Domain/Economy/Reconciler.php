<?php

namespace App\Domain\Economy;

use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GFT-073 / GFT-074 — the reconciliation engine (A.7d).
 *
 * docs/02 §15 rule 12: "Reconciliation is a job, not a hope."
 *
 * The claim being checked is rule 4: for every user, the signed sum of their ledger
 * movements must equal their wallet balance. A mismatch means a balance moved without a
 * ledger row beside it, which is the one thing the money rules exist to prevent — so a
 * discrepancy names the user and the delta rather than reporting a count.
 *
 * This runs as one grouped query per currency rather than per user: a per-user loop would
 * be fine at 8 users and hopeless at 800,000.
 */
class Reconciler
{
    /**
     * @return array{
     *     ok: bool,
     *     checked_at: string,
     *     currencies: array<string, array{wallets: int, ledger_rows: int, mismatches: array<int, array<string, int|string>>}>
     * }
     */
    public function run(): array
    {
        $report = [
            'ok'         => true,
            'checked_at' => now()->toIso8601ZuluString(),
            'currencies' => [],
        ];

        foreach ([
            Wallet::COIN    => ['coin_transactions', 'coin_balance'],
            Wallet::DIAMOND => ['diamond_transactions', 'diamond_balance'],
        ] as $currency => [$table, $column]) {
            $result = $this->reconcileCurrency($table, $column);

            $report['currencies'][$currency] = $result;

            if ($result['mismatches'] !== []) {
                $report['ok'] = false;
            }
        }

        if (! $report['ok']) {
            // A.7d — "any mismatch raises an alert naming the user and the delta".
            // Logging at error level is the alert hook; docs/07 wires it to on-call.
            Log::error('Reconciliation found a ledger/wallet mismatch.', $report);
        }

        return $report;
    }

    /**
     * @return array{wallets: int, ledger_rows: int, mismatches: array<int, array<string, int|string>>}
     */
    protected function reconcileCurrency(string $table, string $column): array
    {
        // Signed sum per user, straight from the ledger.
        $ledger = DB::table($table)
            ->selectRaw('user_id')
            ->selectRaw("SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) AS net")
            ->selectRaw('COUNT(*) AS rows_counted')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $wallets = DB::table('wallets')->select('user_id', $column.' AS balance')->get();

        $mismatches = [];
        $rows = 0;

        foreach ($wallets as $wallet) {
            $entry = $ledger->get($wallet->user_id);
            $ledgerTotal = (int) ($entry->net ?? 0);
            $rows += (int) ($entry->rows_counted ?? 0);
            $balance = (int) $wallet->balance;

            if ($ledgerTotal !== $balance) {
                $mismatches[] = [
                    'user_id'        => (int) $wallet->user_id,
                    'wallet_balance' => $balance,
                    'ledger_total'   => $ledgerTotal,
                    'delta'          => $balance - $ledgerTotal,
                ];
            }
        }

        // A ledger row for a user with no wallet row is also a break — it would otherwise
        // pass unnoticed because the loop above only walks wallets.
        $orphans = $ledger->keys()->diff($wallets->pluck('user_id'));

        foreach ($orphans as $userId) {
            $mismatches[] = [
                'user_id'        => (int) $userId,
                'wallet_balance' => 0,
                'ledger_total'   => (int) $ledger->get($userId)->net,
                'delta'          => -(int) $ledger->get($userId)->net,
                'note'           => 'ledger rows exist but the user has no wallet',
            ];
        }

        return [
            'wallets'     => $wallets->count(),
            'ledger_rows' => $rows,
            'mismatches'  => $mismatches,
        ];
    }
}
