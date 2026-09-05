<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §12 — a DM thread or a group (epic D.4).
 *
 * `last_message_at` is denormalised so the DM list — the busiest screen in the app — sorts
 * without joining `messages`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 10)->default('direct');       // direct group
            // Null for a direct thread: its name is whoever the other person is, which the
            // client renders from the participant list rather than a stored string that
            // would go stale the moment they change their display name.
            $table->string('title', 100)->nullable();
            $table->string('avatar_url', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
