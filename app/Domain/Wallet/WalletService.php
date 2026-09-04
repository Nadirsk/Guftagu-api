<?php

namespace App\Domain\Wallet;

use App\Domain\Audit\AuditLogger;
use App\Models\AdminUser;
use App\Models\CoinTransaction;
use App\Models\DiamondTransaction;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * GFT-029 / GFT-030 — the only sanctioned way to move a balance.
 *
 * Every rule in docs/02 §15 is enforced here rather than left to callers:
 *
 *  1. Integers only — amounts are `int`, never float.
 *  2. Every movement writes a ledger row **in the same transaction** as the balance change.
 *  4. `balance_before` / `balance_after` are recorded so drift is detectable.
 *  5. The wallet row is locked `FOR UPDATE` before it is read.
 *  7. An idempotency key, when supplied, makes a replay return the original row.
 * 11. Manual adjustments require a note, carry `performed_by`, and write an audit entry.
 *
 * A caller that reaches around this class and updates `wallets` directly breaks rule 2 and
 * the nightly reconciliation job will find it.
 */
class WalletService
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    /** Wallets are created lazily — a user who has never transacted still needs one to show. */
    public function forUser(User $user): Wallet
    {
        return Wallet::firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * A manual admin credit or debit (A.3d).
     *
     * @param  'coin'|'diamond'  $currency
     * @param  'credit'|'debit'  $direction
     *
     * @throws WalletException
     */
    public function adjust(
        User $user,
        string $currency,
        string $direction,
        int $amount,
        string $note,
        AdminUser $actor,
        ?string $idempotencyKey = null,
    ): LedgerTransaction {
        if ($amount <= 0) {
            throw new WalletException('INVALID_AMOUNT', 'The amount must be a positive whole number.');
        }

        if (trim($note) === '') {
            // A.3d: an adjustment without a note is rejected. Money that moves without a
            // stated reason is money nobody can explain later.
            throw new WalletException('NOTE_REQUIRED', 'A note is required for a manual adjustment.', 422);
        }

        if (! in_array($currency, [Wallet::COIN, Wallet::DIAMOND], true)) {
            throw new WalletException('INVALID_CURRENCY', 'Currency must be coin or diamond.', 422);
        }

        if (! in_array($direction, [LedgerTransaction::CREDIT, LedgerTransaction::DEBIT], true)) {
            throw new WalletException('INVALID_DIRECTION', 'Direction must be credit or debit.', 422);
        }

        // Replays return the original row rather than moving money twice (§15 rule 7).
        if ($idempotencyKey !== null) {
            $existing = $this->modelFor($currency)::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $transaction = DB::transaction(function () use (
            $user, $currency, $direction, $amount, $note, $actor, $idempotencyKey
        ) {
            // §15 rule 5 — lock before reading a balance that precedes a write. Without
            // this, two concurrent adjustments both read the same "before" and one is lost.
            $wallet = Wallet::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first() ?? Wallet::create(['user_id' => $user->id]);

            $before = $wallet->balanceOf($currency);

            if ($direction === LedgerTransaction::DEBIT && $amount > $before) {
                throw new WalletException(
                    'INSUFFICIENT_BALANCE',
                    'That is more than the user holds.',
                    422,
                    ['required' => $amount, 'available' => $before],
                );
            }

            $after = $direction === LedgerTransaction::CREDIT ? $before + $amount : $before - $amount;

            $balanceColumn = $currency === Wallet::DIAMOND ? 'diamond_balance' : 'coin_balance';

            $wallet->forceFill([
                $balanceColumn => $after,
                'version'      => $wallet->version + 1,
            ])->save();

            // §15 rule 2 — same transaction, always.
            return $this->modelFor($currency)::create([
                'wallet_id'       => $wallet->id,
                'user_id'         => $user->id,
                'direction'       => $direction,
                'amount'          => $amount,
                'balance_before'  => $before,
                'balance_after'   => $after,
                'type'            => $direction === LedgerTransaction::CREDIT
                    ? LedgerTransaction::TYPE_ADMIN_CREDIT
                    : LedgerTransaction::TYPE_ADMIN_DEBIT,
                'idempotency_key' => $idempotencyKey,
                'performed_by'    => $actor->id,
                'note'            => trim($note),
            ]);
        });

        // Audited after commit, so a rolled-back adjustment leaves no record claiming it happened.
        $this->audit->log(
            $actor,
            $direction === LedgerTransaction::CREDIT ? 'wallet.manual_credit' : 'wallet.manual_debit',
            'wallet',
            User::class,
            $user->id,
            ['balance' => $transaction->balance_before],
            [
                'balance'   => $transaction->balance_after,
                'currency'  => $currency,
                'amount'    => $amount,
                'note'      => $transaction->note,
                'ledger_id' => $transaction->uuid,
            ],
        );

        return $transaction;
    }

    /** GFT-030 — an admin freeze blocks the user, not the admin. */
    public function setFrozen(User $user, bool $frozen, string $reason, AdminUser $actor): Wallet
    {
        $wallet = $this->forUser($user);
        $before = $wallet->is_frozen;

        $wallet->forceFill(['is_frozen' => $frozen])->save();

        $this->audit->log(
            $actor,
            $frozen ? 'wallet.freeze' : 'wallet.unfreeze',
            'wallet',
            User::class,
            $user->id,
            ['is_frozen' => $before],
            ['is_frozen' => $frozen, 'reason' => $reason],
        );

        return $wallet->refresh();
    }

    /**
     * GFT-027 — the level half. `$levelId` null clears the override and returns the user
     * to whatever the wallet's own lifetime counters resolve to; the type check against
     * `$levelId` happens in the controller, before this is called, since a mismatched
     * type is a validation error, not a wallet-service concern.
     */
    public function setLevelOverride(User $user, string $type, ?int $levelId, AdminUser $actor): Wallet
    {
        $wallet = $this->forUser($user);
        $column = $type === 'charm' ? 'charm_level_override_id' : 'wealth_level_override_id';
        $before = $wallet->{$column};

        $wallet->forceFill([$column => $levelId])->save();

        $this->audit->log(
            $actor,
            'wallet.level_override',
            'wallet',
            User::class,
            $user->id,
            [$column => $before],
            [$column => $levelId],
        );

        return $wallet->refresh();
    }

    /**
     * §15 rule 4 as a runnable check: walk a user's ledger and prove each row's
     * `balance_before` equals the previous `balance_after`, and that the last one equals
     * the wallet. The nightly reconciliation job is the real home for this; exposing it
     * here lets the panel and the tests assert integrity on demand.
     *
     * @return array{ok: bool, checked: int, wallet_balance: int, ledger_balance: int, breaks: array<int, array<string, int|string>>}
     */
    public function verifyIntegrity(User $user, string $currency = Wallet::COIN): array
    {
        $wallet = $this->forUser($user);

        $rows = $this->modelFor($currency)::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['id', 'direction', 'amount', 'balance_before', 'balance_after']);

        $breaks = [];
        $running = 0;

        foreach ($rows as $index => $row) {
            if ($row->balance_before !== $running) {
                $breaks[] = [
                    'ledger_id' => $row->id,
                    'position'  => $index,
                    'expected'  => $running,
                    'found'     => $row->balance_before,
                ];
            }

            $running = $row->balance_after;
        }

        $walletBalance = $wallet->balanceOf($currency);

        if ($running !== $walletBalance) {
            $breaks[] = ['ledger_id' => 'final', 'expected' => $walletBalance, 'found' => $running];
        }

        return [
            'ok'             => $breaks === [],
            'checked'        => $rows->count(),
            'wallet_balance' => $walletBalance,
            'ledger_balance' => $running,
            'breaks'         => $breaks,
        ];
    }

    /** @return class-string<LedgerTransaction> */
    protected function modelFor(string $currency): string
    {
        return $currency === Wallet::DIAMOND ? DiamondTransaction::class : CoinTransaction::class;
    }
}
