<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §5.1.
 *
 * This table is a **cached projection of the ledger, not the truth** (§15 rule 2). Every
 * balance change must be accompanied by a transaction row in the same DB transaction.
 * All amounts are integer counts — never float (§15 rule 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('coin_balance')->default(0);         // spend currency
            $table->unsignedBigInteger('diamond_balance')->default(0);      // earn currency
            $table->unsignedBigInteger('frozen_coins')->default(0);
            $table->unsignedBigInteger('frozen_diamonds')->default(0);
            $table->unsignedBigInteger('lifetime_coins_purchased')->default(0);
            $table->unsignedBigInteger('lifetime_coins_spent')->default(0);
            $table->unsignedBigInteger('lifetime_diamonds_earned')->default(0);
            $table->unsignedBigInteger('lifetime_withdrawn_paise')->default(0);
            $table->boolean('is_frozen')->default(false);                   // admin freeze (A.3)
            $table->unsignedInteger('version')->default(0);                 // optimistic-lock counter
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
