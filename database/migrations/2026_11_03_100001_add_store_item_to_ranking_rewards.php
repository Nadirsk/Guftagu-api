<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ranking rewards now work like check-in days: a row pays coins/diamonds (`reward_value`)
 * or a store cosmetic — `reward_type` is then the item's StoreItem type, `store_item_id`
 * names it and `duration_days` is how long it is granted for (NULL = permanent).
 *
 * A rank can also carry several rows now (the app's Weekly List card has three slots,
 * capped at RankingReward::MAX_PER_RANK), so a payout is one per reward row rather than
 * one per user — the unique index moves to (snapshot, user, reward).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ranking_rewards', function (Blueprint $table) {
            $table->foreignId('store_item_id')->nullable()->after('reward_value')
                ->constrained('store_items')->nullOnDelete();
            $table->unsignedSmallInteger('duration_days')->nullable()->after('store_item_id');
        });

        Schema::table('ranking_reward_payouts', function (Blueprint $table) {
            $table->unsignedBigInteger('ranking_reward_id')->nullable()->after('user_id');
            $table->unsignedBigInteger('store_item_id')->nullable()->after('reward_value');
            $table->unsignedSmallInteger('duration_days')->nullable()->after('store_item_id');

            // The new index goes in first: the old one is what backs the snapshot_id foreign key.
            $table->unique(['snapshot_id', 'user_id', 'ranking_reward_id'], 'ranking_payouts_snapshot_user_reward_unique');
            $table->dropUnique(['snapshot_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ranking_reward_payouts', function (Blueprint $table) {
            $table->unique(['snapshot_id', 'user_id']);
            $table->dropUnique('ranking_payouts_snapshot_user_reward_unique');
            $table->dropColumn(['ranking_reward_id', 'store_item_id', 'duration_days']);
        });

        Schema::table('ranking_rewards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_item_id');
            $table->dropColumn('duration_days');
        });
    }
};
