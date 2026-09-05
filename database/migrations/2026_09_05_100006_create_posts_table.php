<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moments — docs/02 §12, epic D.3d (**descope lever #1**).
 *
 * Two things here are deliberate:
 *
 *  1. **`like_count` and `comment_count` are denormalised.** The feed renders them on every
 *     row; a COUNT subquery per post is how a feed page gets slow. They are written in the
 *     same transaction as the row they count, so they cannot drift.
 *
 *  2. **`is_hidden` is not a delete.** D.3d's moderation hook has to be reversible: the
 *     author still sees their own post, nobody else does, and a Moderator can undo it.
 *     Deletion is the author's own act and goes through `deleted_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 10)->default('text');         // text image audio
            $table->text('body')->nullable();
            $table->json('media_urls')->nullable();
            $table->string('visibility', 12)->default('public'); // public followers private
            $table->unsignedInteger('like_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);
            $table->boolean('is_hidden')->default(false);
            $table->foreignId('hidden_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('hidden_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            // A profile grid, and the feed's visibility filter.
            $table->index(['user_id', 'created_at']);
            $table->index(['visibility', 'is_hidden', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
