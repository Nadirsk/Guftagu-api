<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D.2d — in-room chat. Persisted (not client-broadcast-only) so a flagged message has a
 * row a moderator can actually review — the same reason DM messages (docs/02 §12) are
 * stored rather than relayed. Deliberately separate from `messages` (D.4, 1:1/group DM):
 * a room message has no thread, no delivery receipts, and disappears once nobody scrolls
 * back far enough to see it — there is no "conversation" here to attach it to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            // Nullable so a deleted account does not take the room's chat history with it.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('body', 500);
            $table->timestamps();

            // Keyset pagination reads this index and nothing else — same shape as
            // `messages`' (conversation_id, id).
            $table->index(['room_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_messages');
    }
};
