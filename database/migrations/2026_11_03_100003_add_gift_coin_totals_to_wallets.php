<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wealth and charm levels, as the product defines them: wealth is the coin value of the
 * gifts a user has **sent in rooms**, charm the coin value of the gifts they have
 * **received in rooms** — a 10-coin gift moves the sender's wealth and the receiver's
 * charm by 10 each. (Before this, wealth read every coin spent — store, VIP, anything —
 * and charm read diamonds, a different unit from the coin thresholds admins set.)
 *
 * Counters rather than a SUM over `gift_transactions` on every read, the same reason the
 * other lifetime totals live on the wallet; backfilled from that ledger here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->unsignedBigInteger('lifetime_gift_coins_sent')->default(0)->after('lifetime_diamonds_earned');
            $table->unsignedBigInteger('lifetime_gift_coins_received')->default(0)->after('lifetime_gift_coins_sent');
        });

        foreach (['sender_id' => 'lifetime_gift_coins_sent', 'receiver_id' => 'lifetime_gift_coins_received'] as $party => $column) {
            $totals = DB::table('gift_transactions')
                ->whereNotNull('room_id')
                ->groupBy($party)
                ->selectRaw("{$party} AS user_id, SUM(coin_value) AS total")
                ->get();

            foreach ($totals as $row) {
                DB::table('wallets')->where('user_id', $row->user_id)->update([$column => (int) $row->total]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn(['lifetime_gift_coins_sent', 'lifetime_gift_coins_received']);
        });
    }
};
