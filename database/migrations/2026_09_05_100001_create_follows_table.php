<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §12 — the follow graph (epic D.3b).
 *
 * Indexed in **both** directions. "Who follows me" and "who do I follow" are different
 * queries over the same rows, and docs/02 §14 names the follower list as one of the hot
 * ones — hence `(following_id, created_at)` alongside its mirror.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('following_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // The unique pair is what makes following twice idempotent in the database
            // rather than only in the service — two taps in flight cannot both insert.
            $table->unique(['follower_id', 'following_id']);
            $table->index(['follower_id', 'created_at']);
            $table->index(['following_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
