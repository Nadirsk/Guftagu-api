<?php

namespace App\Domain\Store;

use App\Domain\Wallet\WalletService;
use App\Events\Rooms\GiftSent;
use App\Models\Gift;
use App\Models\GiftTransaction;
use App\Models\Room;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
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
    /** A repeat tap within this window counts toward the same combo (client-facing only, not stored as a group). */
    protected const COMBO_WINDOW_SECONDS = 10;

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
    ): array {
        if ($idempotencyKey !== null) {
            $existing = GiftTransaction::where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return [$existing, $this->comboCount($sender, $gift, $room, $existing->created_at)];
            }
        }

        if ($sender->id === $receiver->id) {
            throw new GiftException('VALIDATION_ERROR', 'You cannot send a gift to yourself.', 422);
        }

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

        $combo = $this->comboCount($sender, $gift, $room, $transaction->created_at);

        if ($room !== null) {
            GiftSent::dispatch($room, $transaction, $gift, $sender, $receiver, $combo);
        }

        return [$transaction, $combo];
    }

    /** How many of this same gift this sender has sent in this room in the last few seconds. */
    protected function comboCount(User $sender, Gift $gift, ?Room $room, Carbon $asOf): int
    {
        return GiftTransaction::query()
            ->where('sender_id', $sender->id)
            ->where('gift_id', $gift->id)
            ->when($room !== null, fn ($q) => $q->where('room_id', $room->id), fn ($q) => $q->whereNull('room_id'))
            ->where('created_at', '>=', $asOf->copy()->subSeconds(self::COMBO_WINDOW_SECONDS))
            ->count();
    }
}
