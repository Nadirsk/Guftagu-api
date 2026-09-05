<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §12 — one row per person per thread (epic D.4).
 *
 * **`unread_count` lives here, not on the conversation.** Two people in one thread have two
 * different unread counts, and the DM list has to show the caller's own without touching
 * `messages` at all.
 *
 * **The two high-water marks are the whole delivery-receipt mechanism** (WhatsApp-style
 * ✓ / ✓✓ / ✓✓ blue). The obvious design — a row per (message, recipient) — costs
 * `messages × recipients` rows: a 50-person group writes 49 rows for every message sent,
 * and every one of them is read only to render a tick. A mark per person answers the same
 * question in `O(participants)` total:
 *
 *     my message #42 is  read      when every other active participant's
 *                                  last_read_message_id      >= 42
 *                        delivered when every other active participant's
 *                                  last_delivered_message_id >= 42
 *                        sent      otherwise
 *
 * What this trades away: the exact moment each person read each *older* message. The marks
 * remember where somebody has reached, not the history of how they got there, so a
 * per-message "read by X at 10:42" screen can only be exact for recent messages.
 * `delivered_at` / `read_at` record when each mark last moved, which is what the tick and
 * the "last seen this thread" line actually need.
 *
 * Leaving a thread sets `left_at` rather than deleting the row: the history stays readable
 * to the person who left, and nothing said afterwards reaches them — and their stale marks
 * stop holding everyone else's ticks back, because the aggregate skips inactive rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 10)->default('member');       // owner admin member
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();

            // Neither mark is a foreign key: they are high-water marks, and a message being
            // deleted must not drag a mark backwards or null it out.
            $table->unsignedBigInteger('last_delivered_message_id')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->boolean('is_muted')->default(false);
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'left_at']);
            // The aggregate behind every tick: the marks of everyone still in a thread.
            $table->index(['conversation_id', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');
    }
};
