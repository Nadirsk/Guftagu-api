<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §12 — chat messages (epic D.4).
 *
 * **`is_deleted` and `deleted_for` are not the same thing.** `is_deleted` is "delete for
 * everyone" — the sender's retraction. `deleted_for` is a JSON array of user ids for whom
 * the row is hidden, i.e. "delete for me". Collapsing the two into one boolean deletes
 * other people's copies of a message they were entitled to keep.
 *
 * docs/02 §12 also notes **monthly partitions**. Not applied: MySQL 8 cannot partition a
 * table that has foreign keys, and the FKs are worth more than the partitions until volume
 * says otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            // Nullable so a `system` message has no author, and so deleting an account does
            // not take the other side's history with it.
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 10)->default('text');         // text image audio video gift system
            $table->text('body')->nullable();
            $table->string('media_url', 500)->nullable();
            $table->json('media_meta')->nullable();
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->boolean('is_deleted')->default(false);
            $table->json('deleted_for')->nullable();
            $table->timestamps();

            // Keyset pagination reads this index and nothing else.
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
