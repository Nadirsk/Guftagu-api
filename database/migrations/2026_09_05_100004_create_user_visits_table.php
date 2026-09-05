<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §12 — the profile-visitor list (D.3, GFT-227).
 *
 * One row per (visitor, profile) pair, bumped on each visit. A row per view would make the
 * visitor list read as "the same person forty times" instead of a list of people, and it is
 * a list of people the screen is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visitor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('visit_count')->default(1);
            $table->timestamp('visited_at')->useCurrent();
            $table->timestamps();

            $table->unique(['visitor_id', 'profile_id']);
            // The list itself: my visitors, most recent first.
            $table->index(['profile_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_visits');
    }
};
