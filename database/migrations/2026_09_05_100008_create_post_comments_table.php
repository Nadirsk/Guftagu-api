<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §12 — comments on a moment, one level of replies via `parent_id`.
 *
 * Addressed by uuid on the wire (docs/03 §2.4) but ordered and joined by `id`: a thread
 * reads in insertion order, and a uuid does not sort.
 *
 * `is_deleted` is a tombstone rather than a removal. Replies hang off this node, and a hole
 * in the middle of a thread orphans them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('post_comments')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['post_id', 'created_at']);
            $table->index(['parent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_comments');
    }
};
