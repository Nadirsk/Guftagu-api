<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §11 — suspend / ban with a mandatory reason (A.3c).
 *
 * The (user_id, is_active, expires_at) index matters more than most: docs/02 §14 notes the
 * sanctions check runs on every room join, chat message and gift send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sanctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);         // warning mute room_ban temp_ban permanent_ban shadow_ban
            $table->string('scope', 10)->default('global');
            $table->unsignedBigInteger('room_id')->nullable();
            $table->string('reason', 500);      // mandatory — no silent bans
            $table->unsignedBigInteger('report_id')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('starts_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sanctions');
    }
};
