<?php

namespace App\Domain\Store;

use App\Domain\Wallet\WalletService;
use App\Events\Rooms\GiftSent;
use App\Models\Gift;
use App\Models\GiftTransaction;
use App\Models\Room;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Sends a gift: debits the sender's coins, credits the receiver's diamonds, and records
 * the send at the individual-transaction level in `gift_transactions` — the ledger a
 * fixed-window, gift-scoped campaign event's progress metric sums from, and now also the
 * mobile app's own send path (D.6a) — previously only the catalogue existed and nothing
 * could call this at all.
 */
class GiftSendService
{
    public function __construct(
        protected WalletService $wallets,
        protected InventoryService $inventory,
    ) {
    }

    /** @throws GiftException */
    public function send(
        User $sender,
        User $receiver,
        Gift $gift,
        int $quantity = 1,
        ?Room $room = null,
        ?string $idempotencyKey = null,
        ?string $batch = null,
    ): GiftTransaction {
        if ($idempotencyKey !== null) {
            $existing = GiftTransaction::where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        // Sending a gift to yourself is allowed, as in Mazag: you pay the coins and earn the
        // diamonds back at the gift's diamond rate, so there is nothing to farm.

        if ($quantity < 1) {
            throw new GiftException('VALIDATION_ERROR', 'Quantity must be at least one.', 422);
        }

        if (! $gift->isAvailable()) {
            throw new GiftException('GIFT_UNAVAILABLE', 'That gift is not available right now.', 409);
        }

        if ($gift->required_vip_tier_id !== null && ! $this->inventory->meetsTier($sender, $gift->required_vip_tier_id)) {
            throw new GiftException('VIP_TIER_REQUIRED', 'Your VIP tier is not high enough for that gift.', 403);
        }

        $coinValue = $gift->coin_price * $quantity;
        $diamondValue = $gift->diamond_value * $quantity;

        $transaction = DB::transaction(function () use ($sender, $receiver, $room, $gift, $quantity, $coinValue, $diamondValue, $idempotencyKey) {
            // Locked and re-checked inside the transaction: two concurrent sends of the
            // last unit of a limited drop must not both succeed.
            if ($gift->is_limited && $gift->stock !== null) {
                $locked = Gift::query()->whereKey($gift->id)->lockForUpdate()->first();

                if ($locked->stock < $quantity) {
                    throw new GiftException('GIFT_UNAVAILABLE', 'That gift just sold out.', 409);
                }

                $locked->decrement('stock', $quantity);
            }

            $this->wallets->debitSystem($sender, Wallet::COIN, $coinValue, 'gift_send', $idempotencyKey);
            $this->wallets->creditSystem($receiver, Wallet::DIAMOND, $diamondValue, 'gift_receive', $idempotencyKey);

            // Wealth and charm levels count room gifts only, by coin value — the sender's
            // wealth and the receiver's charm move by the same amount.
            if ($room !== null) {
                Wallet::query()->where('user_id', $sender->id)->increment('lifetime_gift_coins_sent', $coinValue);
                Wallet::query()->where('user_id', $receiver->id)->increment('lifetime_gift_coins_received', $coinValue);
            }

            return GiftTransaction::create([
                'sender_id'        => $sender->id,
                'receiver_id'      => $receiver->id,
                'room_id'          => $room?->id,
                'gift_id'          => $gift->id,
                'gift_category_id' => $gift->category_id,
                'quantity'         => $quantity,
                'coin_value'       => $coinValue,
                'idempotency_key'  => $idempotencyKey,
            ]);
        });

        if ($room !== null) {
            try {
                GiftSent::dispatch($room, $transaction, $gift, $sender, $receiver, $batch);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $transaction;
    }
}
