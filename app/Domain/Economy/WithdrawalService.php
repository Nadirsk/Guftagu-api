<?php

namespace App\Domain\Economy;

use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\SettingsRepository;
use App\Models\AdminUser;
use App\Models\DiamondTransaction;
use App\Models\LedgerTransaction;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;

/**
 * GFT-069 / GFT-070 — the withdrawal review flow (A.7b).
 *
 * docs/02 §15 rule 10: **freeze, then pay.** Requesting moves diamonds out of the
 * spendable balance into `frozen_diamonds` immediately. Approval converts frozen → paid;
 * rejection returns them. The two are mutually exclusive because both run inside one
 * transaction against a row locked `FOR UPDATE`, and both refuse to act on a request that
 * is not still pending.
 *
 * Never optimistically decrement, and never leave a balance double-spendable.
 */
class WithdrawalService
{
    public function __construct(
        protected RateResolver $rates,
        protected SettingsRepository $settings,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * Raise a request and freeze the diamonds behind it.
     *
     * The mobile app will call this once D.6d exists; for now the seeder and tests do, so
     * the review queue has something real to review.
     *
     * @throws EconomyException
     */
    public function request(User $user, int $diamonds, string $method = 'upi'): Withdrawal
    {
        if ($diamonds < 1) {
            throw new EconomyException('VALIDATION_ERROR', 'A withdrawal must be for at least one diamond.', 422);
        }

        $minimum = $this->settings->int('economy.withdrawal_minimum_diamonds', 0);

        if ($minimum > 0 && $diamonds < $minimum) {
            throw new EconomyException(
                'BELOW_MINIMUM_WITHDRAWAL',
                "The minimum withdrawal is {$minimum} diamonds.",
                402,
                ['minimum' => $minimum, 'requested' => $diamonds],
            );
        }

        // The rate is resolved once, here, and stored on the row. A.7a: approving this
        // next week must not re-price it.
        $rate = $this->rates->require(RateResolver::DIAMOND_TO_INR);

        return DB::transaction(function () use ($user, $diamonds, $method, $rate) {
            $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->first()
                ?? Wallet::create(['user_id' => $user->id]);

            if ($wallet->availableOf(Wallet::DIAMOND) < $diamonds) {
                throw new EconomyException(
                    'INSUFFICIENT_BALANCE',
                    'That is more than the user has available to withdraw.',
                    402,
                    ['requested' => $diamonds, 'available' => $wallet->availableOf(Wallet::DIAMOND)],
                );
            }

            // Freeze: the balance stays put, but the amount is no longer spendable.
            $wallet->forceFill([
                'frozen_diamonds' => $wallet->frozen_diamonds + $diamonds,
                'version'         => $wallet->version + 1,
            ])->save();

            $gross = $this->rates->convert($diamonds, $rate);

            return Withdrawal::create([
                'user_id'            => $user->id,
                'diamonds'           => $diamonds,
                'gross_paise'        => $gross,
                'commission_paise'   => 0,   // ⚠ CI-02/CI-03 supply the commission and TDS policy
                'tds_paise'          => 0,
                'net_paise'          => $gross,
                'rate_numerator'     => $rate->rate_numerator,
                'rate_denominator'   => $rate->rate_denominator,
                'conversion_rate_id' => $rate->id,
                'method'             => $method,
                'status'             => Withdrawal::PENDING,
                'requested_at'       => now(),
            ]);
        });
    }

    /**
     * Approve. Above the configured threshold this only *advances* the request to
     * `pending_super_approval` — GFT-070, and the SLA's second sign-off on large payouts.
     *
     * @throws EconomyException
     */
    public function approve(Withdrawal $withdrawal, AdminUser $actor): Withdrawal
    {
        $threshold = $this->settings->int('economy.withdrawal_super_approval_paise', 0);
        $isSuperAdmin = $actor->roleKey() === Role::SUPER_ADMIN;

        $result = DB::transaction(function () use ($withdrawal, $actor, $threshold, $isSuperAdmin) {
            /** @var Withdrawal $fresh */
            $fresh = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->first();

            if ($fresh->status === Withdrawal::PENDING_SUPER) {
                if (! $isSuperAdmin) {
                    throw new EconomyException(
                        'FORBIDDEN',
                        'This payout is above the high-value threshold and needs a Super Admin.',
                        403,
                    );
                }

                return $this->settle($fresh, $actor, secondApproval: true);
            }

            if ($fresh->status !== Withdrawal::PENDING) {
                throw new EconomyException(
                    'BAD_REQUEST',
                    "That request is already {$fresh->status}.",
                    400,
                    ['status' => $fresh->status],
                );
            }

            // High value and not yet double-signed: park it rather than pay it.
            if ($threshold > 0 && $fresh->net_paise >= $threshold && ! $isSuperAdmin) {
                $fresh->forceFill([
                    'status'      => Withdrawal::PENDING_SUPER,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                ])->save();

                return $fresh;
            }

            return $this->settle($fresh, $actor, secondApproval: $isSuperAdmin && $threshold > 0 && $fresh->net_paise >= $threshold);
        });

        $this->audit->log(
            $actor,
            $result->status === Withdrawal::PENDING_SUPER ? 'withdrawal.escalate' : 'withdrawal.approve',
            'payouts',
            Withdrawal::class,
            $result->id,
            ['status' => Withdrawal::PENDING],
            ['status' => $result->status, 'net_paise' => $result->net_paise],
        );

        return $result;
    }

    /**
     * Reject and return the frozen diamonds — exactly the amount frozen, no more.
     *
     * @throws EconomyException
     */
    public function reject(Withdrawal $withdrawal, string $reason, AdminUser $actor): Withdrawal
    {
        if (trim($reason) === '') {
            throw new EconomyException('VALIDATION_ERROR', 'A reason is required to reject a payout.', 422);
        }

        $result = DB::transaction(function () use ($withdrawal, $reason, $actor) {
            /** @var Withdrawal $fresh */
            $fresh = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->first();

            if (! in_array($fresh->status, [Withdrawal::PENDING, Withdrawal::PENDING_SUPER], true)) {
                throw new EconomyException(
                    'BAD_REQUEST',
                    "That request is already {$fresh->status}.",
                    400,
                    ['status' => $fresh->status],
                );
            }

            $wallet = Wallet::query()->where('user_id', $fresh->user_id)->lockForUpdate()->first();

            // Unfreeze exactly what was frozen. The balance never moved, so nothing is
            // credited back — the diamonds simply become spendable again.
            $wallet->forceFill([
                'frozen_diamonds' => max(0, $wallet->frozen_diamonds - $fresh->diamonds),
                'version'         => $wallet->version + 1,
            ])->save();

            $fresh->forceFill([
                'status'           => Withdrawal::REJECTED,
                'reviewed_by'      => $actor->id,
                'reviewed_at'      => now(),
                'rejection_reason' => trim($reason),
            ])->save();

            return $fresh;
        });

        $this->audit->log(
            $actor,
            'withdrawal.reject',
            'payouts',
            Withdrawal::class,
            $result->id,
            ['status' => Withdrawal::PENDING],
            ['status' => Withdrawal::REJECTED, 'reason' => $result->rejection_reason, 'diamonds_returned' => $result->diamonds],
        );

        return $result;
    }

    /**
     * The paying half of "freeze, then pay": frozen diamonds leave the wallet for good and
     * a ledger row records it, in the same transaction as the status change.
     */
    protected function settle(Withdrawal $withdrawal, AdminUser $actor, bool $secondApproval): Withdrawal
    {
        $wallet = Wallet::query()->where('user_id', $withdrawal->user_id)->lockForUpdate()->first();

        $before = $wallet->diamond_balance;
        $after = max(0, $before - $withdrawal->diamonds);

        $wallet->forceFill([
            'diamond_balance'          => $after,
            'frozen_diamonds'          => max(0, $wallet->frozen_diamonds - $withdrawal->diamonds),
            'lifetime_withdrawn_paise' => $wallet->lifetime_withdrawn_paise + $withdrawal->net_paise,
            'version'                  => $wallet->version + 1,
        ])->save();

        // §15 rule 2 — a balance never changes without a ledger row beside it.
        DiamondTransaction::create([
            'wallet_id'      => $wallet->id,
            'user_id'        => $withdrawal->user_id,
            'direction'      => LedgerTransaction::DEBIT,
            'amount'         => $withdrawal->diamonds,
            'balance_before' => $before,
            'balance_after'  => $after,
            'type'           => 'withdrawal_settled',
            'reference_type' => Withdrawal::class,
            'reference_id'   => $withdrawal->id,
            'performed_by'   => $actor->id,
            'note'           => "Withdrawal {$withdrawal->uuid} approved",
        ]);

        $withdrawal->forceFill(array_filter([
            'status'             => Withdrawal::APPROVED,
            'reviewed_by'        => $withdrawal->reviewed_by ?? $actor->id,
            'reviewed_at'        => $withdrawal->reviewed_at ?? now(),
            'second_approved_by' => $secondApproval ? $actor->id : null,
            'second_approved_at' => $secondApproval ? now() : null,
        ], fn ($value) => $value !== null))->save();

        return $withdrawal;
    }
}
