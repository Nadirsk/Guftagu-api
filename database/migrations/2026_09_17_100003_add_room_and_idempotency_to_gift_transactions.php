<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D.6a — gift-sending had a domain service (`GiftSendService`) but no route anywhere, and
 * no way to know *where* a gift was sent or to make a resend-on-retry safe. Both needed
 * for the app: `room_id` for "recent gift feed for this room" (docs/03 §6), a unique
 * `idempotency_key` so a retried request cannot record the same send twice even though the
 * wallet side (`WalletService::move`) was already idempotent on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_transactions', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('receiver_id')->constrained()->nullOnDelete();
            $table->string('idempotency_key', 191)->nullable()->unique()->after('coin_value');
        });
    }

    public function down(): void
    {
        Schema::table('gift_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
            $table->dropColumn('idempotency_key');
        });
    }
};
