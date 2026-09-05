<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §12 — one row per (post, user).
 *
 * The unique pair **is** the idempotency. The service catches the duplicate-key error
 * rather than checking first: a check-then-insert is two statements, and a double tap can
 * pass both before either writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['post_id', 'user_id']);
            // "Posts I have liked", and the feed's per-page liked-by-me lookup.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_likes');
    }
};
