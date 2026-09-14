<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gift-send ledger — did not exist anywhere before this migration. `HostGiftTargetResult`
 * only stores a monthly *rollup* (`coins_sent` for a calendar month); nothing recorded a
 * single send at the gift/quantity level, which is what a fixed-window, gift-scoped
 * campaign event ("most Rose Gift sent between two dates") needs to score correctly.
 *
 * Immutable, like `coin_transactions`/`diamond_transactions` (docs/02 §15 rule 3) — a
 * send is a fact; correcting one is a new row, never an edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('receiver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('gift_id')->constrained('gifts')->cascadeOnDelete();
            // Denormalised from gifts.category_id at send time — a category's gifts can be
            // reshuffled later without rewriting history of what was actually sent.
            $table->foreignId('gift_category_id')->nullable()->constrained('gift_categories')->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('coin_value'); // gifts.coin_price * quantity, snapshotted
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sender_id', 'created_at']);
            $table->index(['receiver_id', 'created_at']);
            $table->index(['gift_id', 'created_at']);
            $table->index(['gift_category_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_transactions');
    }
};
