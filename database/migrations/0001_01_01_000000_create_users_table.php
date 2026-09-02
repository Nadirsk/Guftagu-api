<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mobile-app account — docs/02 §2.1. Replaces Laravel's placeholder `users` table.
 *
 * Kept at the framework's original timestamp because everything else references it and
 * this project has no deployed data yet; recreating it as a later migration would only
 * add a drop-and-rebuild step for no benefit.
 *
 * `phone` and `email` are encrypted at rest (docs/01 §6), which makes them unsearchable —
 * hence the `_hash` columns, which are what every lookup actually queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();                     // the only id the mobile API exposes
            $table->string('guftagu_id', 12)->unique();         // human-shareable, e.g. GF8420156

            // TEXT rather than the VARBINARY(255) in docs/02 §2.1. A phone encrypts to ~132
            // bytes under AES-256-GCM, so 255 would fit today — but the envelope grows with
            // both the plaintext (a 191-char email) and the cipher (AES-256-CBC adds a
            // 64-char HMAC, pushing past 255). Truncated ciphertext is unrecoverable and
            // fails at decrypt time, long after the write. TEXT costs nothing here.
            $table->text('phone');
            $table->char('phone_hash', 64)->unique();           // SHA-256 — the searchable key
            $table->string('country_code', 5)->default('+91');
            $table->text('email')->nullable();
            $table->char('email_hash', 64)->nullable()->unique();

            $table->string('password')->nullable();             // OTP-only accounts have none
            $table->string('status', 20)->default('active');    // active suspended banned deleted
            $table->unsignedInteger('agora_uid')->unique();     // Agora requires a numeric uid
            $table->timestamp('last_active_at')->nullable();
            $table->string('registered_ip', 45)->nullable();
            $table->string('consent_version', 20)->nullable();  // DPDPA
            $table->timestamp('consent_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('last_active_at');
            $table->index(['status', 'created_at']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
