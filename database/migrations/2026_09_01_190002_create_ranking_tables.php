<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §8 — A.9c/d.
 *
 * "Live boards are served from Redis sorted sets — zero database reads. A scheduled job
 * snapshots the ZSET into `leaderboard_snapshots` at period close, then pays rewards.
 * **The snapshot is the record; Redis is the working surface.**"
 *
 * Redis arrives with the realtime layer; until then the board is computed from the wallet
 * lifetime counters, which is where wealth and charm come from anyway. The snapshot half
 * — the part that matters for paying people — works either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ranking_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();                // wealth_daily, charm_weekly …
            $table->string('board_type', 20);                   // wealth charm room agency
            $table->string('period', 20);                       // daily weekly monthly all_time
            $table->string('metric', 40);                       // coins_spent diamonds_earned …
            $table->unsignedBigInteger('min_threshold')->default(0);
            $table->unsignedInteger('top_n')->default(100);
            $table->string('reset_cron', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'board_type']);
        });

        Schema::create('leaderboard_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('rule_key', 50);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('rank');
            $table->string('entity_type', 20)->default('user'); // user room agency
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('score');
            $table->timestamps();

            // One entity per rank per period — the snapshot is the record, so it must not
            // be possible to write two different answers for the same slot.
            $table->unique(['rule_key', 'period_start', 'rank']);
            $table->index(['rule_key', 'period_start']);
        });

        Schema::create('ranking_rewards', function (Blueprint $table) {
            $table->id();
            $table->string('rule_key', 50);
            $table->unsignedInteger('rank_from');
            $table->unsignedInteger('rank_to');
            $table->string('reward_type', 30);
            $table->unsignedBigInteger('reward_value');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['rule_key', 'rank_from']);
        });

        Schema::create('ranking_reward_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('leaderboard_snapshots')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reward_type', 30);
            $table->unsignedBigInteger('reward_value');
            $table->string('status', 20)->default('pending');   // pending paid failed
            $table->timestamp('paid_at')->nullable();
            $table->string('transaction_id', 64)->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamps();

            // A.9d — "re-running the payout job pays nothing further". This unique index
            // is what makes that true even if two runs overlap.
            $table->unique(['snapshot_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ranking_reward_payouts');
        Schema::dropIfExists('ranking_rewards');
        Schema::dropIfExists('leaderboard_snapshots');
        Schema::dropIfExists('ranking_rules');
    }
};
