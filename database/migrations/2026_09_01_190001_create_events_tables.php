<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §9 — A.9a/b.
 *
 * `status` stores the operator's *intent* (draft, scheduled, cancelled). Whether a
 * scheduled event is upcoming, live or ended is derived from `starts_at`/`ends_at` at read
 * time — A.9a requires those transitions to happen "with no manual step", and a job that
 * flips a column leaves events stuck live whenever the scheduler stalls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 20)->default('event');       // event tournament lucky_draw
            $table->string('title_en', 150);
            $table->string('title_hi', 150)->nullable();
            $table->text('description')->nullable();
            $table->string('banner_url', 500)->nullable();
            $table->json('rules')->nullable();
            $table->string('entry_type', 10)->default('free');  // free coins invite
            $table->unsignedBigInteger('entry_cost')->default(0);
            $table->json('eligibility')->nullable();
            // dateTime, not timestamp: MySQL gives a *second* TIMESTAMP NOT NULL column in
            // the same table an implicit zero default, which strict mode rejects outright.
            // dateTime also avoids the 2038 ceiling, which matters for scheduled work.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20)->default('draft');     // draft scheduled cancelled
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->unsignedInteger('max_participants')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at']);
            $table->index(['type', 'status']);
        });

        Schema::create('event_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('joined_at')->useCurrent();
            $table->unsignedBigInteger('score')->default(0);
            $table->unsignedInteger('rank')->nullable();
            $table->string('status', 20)->default('joined');    // joined disqualified winner
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index(['event_id', 'score']);
        });

        Schema::create('event_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('rank_from');
            $table->unsignedInteger('rank_to');
            $table->string('reward_type', 30);                  // coins diamonds frame badge vip_days
            $table->unsignedBigInteger('reward_value');
            $table->unsignedInteger('quantity')->nullable();    // NULL = as many as the band holds
            $table->unsignedInteger('claimed_count')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'rank_from']);
        });

        Schema::create('event_reward_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reward_id')->constrained('event_rewards')->cascadeOnDelete();
            $table->unsignedInteger('rank')->nullable();
            $table->string('status', 20)->default('pending');   // pending paid failed
            $table->timestamp('claimed_at')->nullable();
            $table->string('transaction_id', 64)->nullable();
            $table->timestamps();

            // A.9b — "each receives the reward for their band, once". The database says so.
            $table->unique(['event_id', 'user_id']);
        });

        Schema::create('lucky_draws', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->dateTime('draw_at');
            $table->json('prize_pool')->nullable();
            $table->unsignedInteger('winner_count')->default(1);
            $table->string('algorithm', 20)->default('random'); // random weighted

            // Published BEFORE the draw; the seed itself only afterwards. That is what
            // makes the result checkable by anyone who cared enough to write it down.
            $table->char('seed_hash', 64);
            $table->string('seed', 128)->nullable();

            $table->timestamp('drawn_at')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lucky_draws');
        Schema::dropIfExists('event_reward_claims');
        Schema::dropIfExists('event_rewards');
        Schema::dropIfExists('event_participants');
        Schema::dropIfExists('events');
    }
};
