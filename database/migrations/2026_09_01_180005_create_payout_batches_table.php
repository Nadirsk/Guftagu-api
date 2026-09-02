<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/02 §5.4 — GFT-071.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 40)->unique();
            $table->string('type', 30)->default('user_withdrawal');
            $table->unsignedInteger('count')->default(0);
            $table->unsignedBigInteger('total_paise')->default(0);
            $table->string('status', 20)->default('draft');  // draft approved processing completed partial failed
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('file_url', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_batches');
    }
};
