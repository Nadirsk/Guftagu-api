<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `progress_metric` grows beyond `recharge_amount` (docs — Event Builder): `coins_spent`,
 * `diamonds_earned`, and the gift-scoped `gift_value`/`gift_count`. The gift metrics can
 * optionally be narrowed to one specific gift or one whole category — `metric_ref_type`
 * says which table `metric_ref_id` addresses, the same reasoning as
 * `reward_catalog_items.handler_ref_id`, except a reward's `handler_key` always implies
 * exactly one ref table while a gift metric can mean either, hence the extra column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedBigInteger('metric_ref_id')->nullable()->after('progress_metric');
            $table->string('metric_ref_type', 20)->nullable()->after('metric_ref_id'); // gift | gift_category
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['metric_ref_id', 'metric_ref_type']);
        });
    }
};
