<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Check-in days can now pay a cosmetic from the store catalogue (frame, entrance effect,
 * entry banner, bubble…) on top of coins/diamonds. For those, `reward_type` holds the
 * item's StoreItem type, `store_item_id` points at the item and `duration_days` says how
 * long it is granted for (NULL = permanent). `reward_value` stays 1 for them.
 *
 * `daily_checkins` snapshots both so a later catalogue edit never rewrites history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkin_rewards', function (Blueprint $table) {
            $table->foreignId('store_item_id')->nullable()->after('reward_value')
                ->constrained('store_items')->nullOnDelete();
            $table->unsignedSmallInteger('duration_days')->nullable()->after('store_item_id');
        });

        Schema::table('daily_checkins', function (Blueprint $table) {
            $table->unsignedBigInteger('store_item_id')->nullable()->after('reward_value');
            $table->unsignedSmallInteger('duration_days')->nullable()->after('store_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('daily_checkins', function (Blueprint $table) {
            $table->dropColumn(['store_item_id', 'duration_days']);
        });

        Schema::table('checkin_rewards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_item_id');
            $table->dropColumn('duration_days');
        });
    }
};
