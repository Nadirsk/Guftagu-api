<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generalizes `events` into a template-driven screen, alongside (not instead of) the
 * existing rank-band shape (`event_rewards`/`event_reward_claims`) that `event`,
 * `tournament` and `lucky_draw` events already use — those callers are untouched.
 *
 * Two new event types read from the tables added here instead:
 *
 *  - `recharge_activity` — `tier_type = threshold`. A user's cumulative recharge in the
 *    current period (`event_progress`) is compared against each tier's
 *    `threshold_value`; clearing one unlocks its reward bundle, claimed by the user.
 *  - `weekly_star` — `tier_type = rank_range`. Standing comes from the existing
 *    `RankingRule`/`LeaderboardService` this event points at via `ranking_rule_key`
 *    (reusing that engine rather than re-deriving windowed rankings from scratch); a
 *    band's reward bundle is distributed once the period closes, the same way
 *    `RankingRewardPayout` already does for plain coin/diamond rewards.
 *
 * `event_tier_rewards` is what neither `EventReward` nor `RankingReward` support today:
 * several reward lines — a frame AND a VIP grant AND coins — on the *same* tier/band.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // `recharge_activity` | `weekly_star` on top of the existing event/tournament/
            // lucky_draw. What a progress bar measures, for threshold-tier events.
            $table->string('progress_metric', 30)->nullable()->after('type');
            // Recurrence for threshold/rank-range events: daily/weekly/monthly re-open a
            // fresh period on the clock, the same rule LeaderboardService already applies
            // to a RankingRule. NULL means the event's own starts_at/ends_at is the only
            // period there is — unchanged behaviour for event/tournament/lucky_draw.
            $table->string('period', 20)->nullable()->after('ends_at');
            // Which RankingRule drives standings for a rank_range event — reuses that
            // engine instead of re-deriving a windowed leaderboard from scratch.
            $table->string('ranking_rule_key', 50)->nullable()->after('period');
            // The fixed section library (banner/countdown/tier grid/leaderboard/...) an
            // admin composes the screen from. See EventCampaignService::SECTION_TYPES.
            $table->json('layout')->nullable()->after('rules');
        });

        Schema::create('event_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('tier_type', 20); // threshold | rank_range
            // threshold tiers only — daily/weekly/monthly override of the event's own
            // `period`, so one event (e.g. Recharge Activity) can carry both a daily and
            // a monthly ladder side by side, switched by a tab in the app.
            $table->string('period', 20)->nullable();
            $table->unsignedBigInteger('threshold_value')->nullable(); // threshold tiers
            $table->unsignedInteger('rank_from')->nullable();          // rank_range tiers
            $table->unsignedInteger('rank_to')->nullable();            // rank_range tiers
            $table->string('label', 60);
            $table->string('image_url', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'tier_type', 'sort_order']);
        });

        Schema::create('event_tier_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_tier_id')->constrained()->cascadeOnDelete();
            $table->string('reward_type', 30); // coins diamonds vip_days frame bubble entrance_effect badge special_id customize_gift
            // Meaning depends on reward_type: store_items.id for frame/bubble/
            // entrance_effect, vip_tiers.id for vip_days, badges.id for badge; NULL for
            // coins/diamonds (reward_value is the amount) and for the two placeholder
            // types that have no catalogue yet.
            $table->unsignedBigInteger('reward_ref_id')->nullable();
            $table->unsignedBigInteger('reward_value')->nullable();
            $table->unsignedInteger('duration_days')->nullable();
            // Display fallback — required for special_id/customize_gift (no catalogue
            // row to read a name from yet), optional elsewhere.
            $table->string('label', 100)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['event_tier_id', 'sort_order']);
        });

        Schema::create('event_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('period_type', 20); // daily weekly monthly
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('score')->default(0);
            $table->timestamps();

            // One row per user per period per cadence — a Recharge Activity event tracks
            // "daily" and "monthly" as two independent rows for the same user.
            $table->unique(['event_id', 'user_id', 'period_type', 'period_start']);
        });

        Schema::create('event_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_tier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('period_type', 20)->nullable();
            $table->date('period_start');
            $table->string('status', 20)->default('pending'); // pending paid failed
            $table->dateTime('claimed_at')->nullable();
            // A snapshot of every reward line granted (or not — placeholder types are
            // recorded with granted=false, same convention as EventService::payReward).
            $table->json('granted')->nullable();
            $table->timestamps();

            // A.9b's rule generalised: each user gets their tier's bundle once per period.
            $table->unique(['event_tier_id', 'user_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_claims');
        Schema::dropIfExists('event_progress');
        Schema::dropIfExists('event_tier_rewards');
        Schema::dropIfExists('event_tiers');

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['progress_metric', 'period', 'ranking_rule_key', 'layout']);
        });
    }
};
