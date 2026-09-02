<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GFT-018 — the materialised rollup the dashboard reads from.
 *
 * A.2's NFR is explicit: "no query scans a raw transaction table — all figures come from
 * the rollup table or Redis." The ledgers grow without bound, so a dashboard that summed
 * them live would get slower every day and would lock rows the economy needs.
 *
 * One row per day. Recomputed by `stats:rollup`, which is idempotent — re-running a date
 * overwrites it rather than double-counting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();

            // Accounts
            $table->unsignedBigInteger('new_users')->default(0);
            $table->unsignedBigInteger('active_users')->default(0);
            $table->unsignedBigInteger('total_users')->default(0);
            $table->unsignedBigInteger('banned_users')->default(0);

            // Revenue streams, kept apart so A.2b can show them separately and still sum
            // to the ledger total for the range.
            $table->unsignedBigInteger('recharge_coins')->default(0);
            $table->unsignedBigInteger('gifting_coins')->default(0);
            $table->unsignedBigInteger('vip_coins')->default(0);
            $table->unsignedBigInteger('other_coins')->default(0);
            $table->unsignedBigInteger('admin_credit_coins')->default(0);
            $table->unsignedBigInteger('admin_debit_coins')->default(0);
            $table->unsignedBigInteger('diamonds_earned')->default(0);

            // Rooms — always zero until the rooms module lands. Present so the shape of
            // the rollup does not change when it does.
            $table->unsignedBigInteger('rooms_opened')->default(0);
            $table->unsignedBigInteger('peak_live_rooms')->default(0);

            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
