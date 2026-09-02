<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// GFT-003 — email OTP challenge. 10-minute expiry, 5 attempts.
// `POST /admin/auth/login` returns challenge_id; no token is issued until verified.
// Also used for MFA re-entry when granting a `high` risk permission (GFT-122).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_mfa_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->string('otp_hash');                       // hashed, never stored in clear
            $table->enum('purpose', ['login', 'reauth'])->default('login');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->boolean('remember_device')->default(false);
            $table->timestamps();

            $table->index(['admin_user_id', 'purpose', 'consumed_at']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_mfa_challenges');
    }
};
