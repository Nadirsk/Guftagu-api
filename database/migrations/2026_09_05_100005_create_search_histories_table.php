<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recent searches, per user — epic D.3a.
 *
 * The unique triple is the point: re-searching the same term moves the existing row up
 * rather than adding another. A list that repeats one phrase twenty times is a log, not a
 * history, and the screen shows a history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_histories', function (Blueprint $table) {
            $table->id();
            // docs/03 §2.4 — "never leak a sequential id to the app". A bare row id on the
            // delete endpoint would publish how many searches the whole platform has run.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 10)->default('term');         // term user room
            $table->string('term', 100);
            // Set when the entry is a tapped result rather than a typed phrase, so the
            // client can reopen that profile or room instead of rerunning the search.
            $table->string('target_uuid', 36)->nullable();
            $table->timestamp('searched_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'type', 'term']);
            $table->index(['user_id', 'searched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_histories');
    }
};
