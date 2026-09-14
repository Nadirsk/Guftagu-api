<?php

namespace App\Domain\Store;

use App\Domain\Wallet\WalletService;
use App\Models\Gift;
use App\Models\GiftTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Sends a gift: debits the sender's coins, credits the receiver's diamonds, and records
 * the send at the individual-transaction level in `gift_transactions` — the ledger a
 * fixed-window, gift-scoped campaign event's progress metric sums from.
 *
 * There was no gift-sending code path anywhere in the app before this — only the
 * catalogue (`Gift`/`GiftCategory`) and the monthly host-target rollup
 * (`HostGiftTargetResult`) existed. This is the minimal transactional piece real event
 * scoring needs; wiring an actual send into the room/live-gifting UX (animations,
 * combos, target-policy evaluation, realtime broadcast) is a separate, larger feature.
 */
class GiftSendService
{
    public function __construct(protected WalletService $wallets)
    {
    }

    public function send(User $sender, User $receiver, Gift $gift, int $quantity = 1): GiftTransaction
    {
        $coinValue = $gift->coin_price * $quantity;
        $diamondValue = $gift->diamond_value * $quantity;

        return DB::transaction(function () use ($sender, $receiver, $gift, $quantity, $coinValue, $diamondValue) {
            $this->wallets->debitSystem($sender, Wallet::COIN, $coinValue, 'gift_send');
            $this->wallets->creditSystem($receiver, Wallet::DIAMOND, $diamondValue, 'gift_receive');

            return GiftTransaction::create([
                'sender_id'        => $sender->id,
                'receiver_id'      => $receiver->id,
                'gift_id'          => $gift->id,
                'gift_category_id' => $gift->category_id,
                'quantity'         => $quantity,
                'coin_value'       => $coinValue,
            ]);
        });
    }
}
