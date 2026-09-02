<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/02 §2.3 — KYC for withdrawal (A.3b).
// Document numbers, PAN and bank account are encrypted, so they are TEXT for the same
// reason as users.phone: the ciphertext envelope overflows a short VARCHAR.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_kyc', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('full_name', 150);
            $table->string('doc_type', 30);                 // aadhaar pan passport dl
            $table->text('doc_number');                     // encrypted
            $table->string('doc_front_url', 500)->nullable();
            $table->string('doc_back_url', 500)->nullable();
            $table->string('selfie_url', 500)->nullable();
            $table->text('pan')->nullable();                // encrypted
            $table->text('bank_account')->nullable();       // encrypted
            $table->string('ifsc', 20)->nullable();
            $table->string('upi_id', 100)->nullable();
            $table->string('status', 20)->default('pending');   // pending verified rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_kyc');
    }
};
