<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// GFT-002 — lockout after 5 consecutive failures (A.1a, OWASP A07).
// Persisted rather than Redis-only so the audit trail survives a cache flush.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191);
            $table->string('ip', 45)->nullable();
            $table->boolean('successful')->default(false);
            $table->string('reason', 100)->nullable();   // bad_password | locked | suspended | mfa_failed
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['email', 'created_at']);
            $table->index(['ip', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_login_attempts');
    }
};
