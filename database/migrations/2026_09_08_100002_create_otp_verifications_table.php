<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic D.1a — one row per OTP sent, for both channels (email/phone) and both purposes
 * (auth, reset_password). Keyed on `identifier_hash` — the same SHA-256 the `users` table
 * already uses for `phone_hash`/`email_hash` — so verification never touches plaintext.
 *
 * Mirrors AdminMfaChallenge's shape (otp_hash, attempts, expires_at, consumed_at) rather
 * than introducing a second convention for the same idea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 10);          // email | phone
            $table->char('identifier_hash', 64);
            $table->string('purpose', 20);           // auth | reset_password
            $table->string('otp_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['identifier_hash', 'purpose', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_verifications');
    }
};
