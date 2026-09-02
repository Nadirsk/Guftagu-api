<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §5.2 — the diamond ledger.
 *
 * Coins and diamonds get **two separate tables of identical shape** so the currencies can
 * never be confused in a query.
 *
 * Rows are IMMUTABLE (§15 rule 3): no UPDATE, no DELETE, and deliberately no updated_at.
 * A mistake is corrected with a compensating entry, never by editing history.
 *
 * balance_before / balance_after are the audit anchor (§15 rule 4): for a user ordered by
 * id, each row's balance_before must equal the previous row's balance_after, and the last
 * balance_after must equal the wallet. That is what makes drift detectable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diamond_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 6);                     // credit debit
            $table->unsignedBigInteger('amount');               // always positive; direction carries the sign
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');
            $table->string('type', 40);
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('performed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('note', 255)->nullable();            // mandatory for admin adjustments
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diamond_transactions');
    }
};
