<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §11 — C.4c. Append-only.
 *
 * Deliberately separate from `audit_logs`. Audit answers "what did staff change?"; this
 * answers "what was done to users and rooms, and why?" — a moderation review reads this
 * one without wading through settings edits. A.4c requires a force-close to appear in both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('action', 60);
            $table->string('target_type', 60)->nullable();
            $table->string('target_id', 60)->nullable();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['admin_user_id', 'created_at']);
            $table->index(['room_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_logs');
    }
};
