<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §5.4 — A.7b, GFT-069/070. ⚠ CI-03 supplies the real policy.
 *
 * The rate used is **stored on the row** rather than looked up at approval time. A.7a
 * requires that "historical requests are never re-priced": a request made today settles
 * at today's rate even if it is approved next week and the rate changed in between.
 *
 * `pending_super_approval` implements the second-approval rule (GFT-070) — the SLA's
 * "approves high-risk actions such as large payouts".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('diamonds');
            $table->unsignedBigInteger('gross_paise');
            $table->unsignedBigInteger('commission_paise')->default(0);
            $table->unsignedBigInteger('tds_paise')->default(0);
            $table->unsignedBigInteger('net_paise');

            // Frozen at request time so a later rate change cannot re-price this row.
            $table->unsignedBigInteger('rate_numerator');
            $table->unsignedBigInteger('rate_denominator');
            $table->unsignedBigInteger('conversion_rate_id')->nullable();

            $table->string('method', 10)->default('upi');    // bank upi
            $table->text('payout_details')->nullable();      // encrypted

            // pending → approved | pending_super_approval | rejected → processing → paid | failed | reverted
            $table->string('status', 30)->default('pending');

            $table->timestamp('requested_at')->useCurrent();
            $table->foreignId('reviewed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('second_approved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('second_approved_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();

            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('utr', 60)->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'requested_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
