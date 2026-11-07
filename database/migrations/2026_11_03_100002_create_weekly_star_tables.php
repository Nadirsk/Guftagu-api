<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly Star, as the app screen shows it: the admin picks up to three "Event Gifts", and
 * only sending those gifts scores (by coin value) in a round. A campaign runs for N weeks
 * from its start date; every 7 days is one round, and when a round closes its Top 3 are
 * paid automatically (`events:settle-weekly-star`).
 *
 * `event_rounds` is the record of a settled round — who placed, with what score, and
 * when it was paid — so the admin panel can list past weeks without recomputing them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gift_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'gift_id']);
        });

        Schema::create('event_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->json('standings')->nullable();      // [{rank, user_id, display_name, score}]
            $table->unsignedInteger('granted')->default(0);
            $table->dateTime('settled_at')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            // One record per round — what makes the scheduled job safe to re-run.
            $table->unique(['event_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_rounds');
        Schema::dropIfExists('event_gifts');
    }
};
