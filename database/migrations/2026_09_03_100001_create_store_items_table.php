<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the separate `frames` and `entrance_effects` tables with one `store_items`
 * table, discriminated by `type`. Frame, bubble, entry banner and entrance effect are all
 * the same shape in practice — name, artwork, a coin price, an optional VIP gate, an
 * optional rental period — so one table with a handful of type-specific nullable columns
 * beats four near-identical ones. `badges` stays separate: it is earned by the app
 * (`is_auto_awarded`), never purchased, so it does not belong in a purchasable-item table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_items', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20); // frame | bubble | entry_banner | entrance_effect
            $table->string('name', 80);
            $table->string('image_url', 500)->nullable();
            $table->string('animation_url', 500)->nullable();

            // entrance_effect only
            $table->string('animation_type', 10)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('trigger', 20)->nullable(); // vip_entry big_gift level_up event
            $table->unsignedBigInteger('min_gift_coin_value')->nullable();

            // frame only
            $table->string('source', 20)->nullable(); // vip event purchase admin

            $table->unsignedBigInteger('coin_price')->default(0);
            // How many days ownership lasts once bought — the "100000/3Day" style pricing.
            // NULL means a one-time purchase that never expires.
            $table->unsignedInteger('rental_days')->nullable();

            $table->foreignId('required_vip_tier_id')->nullable()->constrained('vip_tiers')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        if (Schema::hasTable('frames')) {
            DB::table('frames')->orderBy('id')->each(function ($frame) {
                DB::table('store_items')->insert([
                    'type'                  => 'frame',
                    'name'                  => $frame->name,
                    'image_url'             => $frame->image_url,
                    'animation_url'         => $frame->animation_url,
                    'source'                => $frame->source,
                    'coin_price'            => $frame->coin_price,
                    'required_vip_tier_id'  => $frame->required_vip_tier_id,
                    'is_active'             => $frame->is_active,
                    'created_at'            => $frame->created_at,
                    'updated_at'            => $frame->updated_at,
                ]);
            });
        }

        if (Schema::hasTable('entrance_effects')) {
            DB::table('entrance_effects')->orderBy('id')->each(function ($effect) {
                DB::table('store_items')->insert([
                    'type'                  => 'entrance_effect',
                    'name'                  => $effect->name,
                    'animation_url'         => $effect->animation_url,
                    'animation_type'        => $effect->animation_type,
                    'duration_ms'           => $effect->duration_ms,
                    'trigger'               => $effect->trigger,
                    'min_gift_coin_value'   => $effect->min_gift_coin_value,
                    'coin_price'            => 0,
                    'required_vip_tier_id'  => $effect->required_vip_tier_id,
                    'is_active'             => $effect->is_active,
                    'created_at'            => $effect->created_at,
                    'updated_at'            => $effect->updated_at,
                ]);
            });
        }

        Schema::dropIfExists('entrance_effects');
        Schema::dropIfExists('frames');
    }

    public function down(): void
    {
        Schema::create('frames', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('image_url', 500)->nullable();
            $table->string('animation_url', 500)->nullable();
            $table->string('source', 20)->default('purchase');
            $table->unsignedBigInteger('coin_price')->default(0);
            $table->foreignId('required_vip_tier_id')->nullable()->constrained('vip_tiers')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'source']);
        });

        Schema::create('entrance_effects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('animation_url', 500)->nullable();
            $table->string('animation_type', 10)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('trigger', 20)->default('vip_entry');
            $table->foreignId('required_vip_tier_id')->nullable()->constrained('vip_tiers')->nullOnDelete();
            $table->unsignedBigInteger('min_gift_coin_value')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'trigger']);
        });

        if (Schema::hasTable('store_items')) {
            DB::table('store_items')->where('type', 'frame')->orderBy('id')->each(function ($item) {
                DB::table('frames')->insert([
                    'name' => $item->name, 'image_url' => $item->image_url, 'animation_url' => $item->animation_url,
                    'source' => $item->source ?? 'purchase', 'coin_price' => $item->coin_price,
                    'required_vip_tier_id' => $item->required_vip_tier_id, 'is_active' => $item->is_active,
                    'created_at' => $item->created_at, 'updated_at' => $item->updated_at,
                ]);
            });

            DB::table('store_items')->where('type', 'entrance_effect')->orderBy('id')->each(function ($item) {
                DB::table('entrance_effects')->insert([
                    'name' => $item->name, 'animation_url' => $item->animation_url, 'animation_type' => $item->animation_type,
                    'duration_ms' => $item->duration_ms, 'trigger' => $item->trigger ?? 'vip_entry',
                    'min_gift_coin_value' => $item->min_gift_coin_value, 'required_vip_tier_id' => $item->required_vip_tier_id,
                    'is_active' => $item->is_active, 'created_at' => $item->created_at, 'updated_at' => $item->updated_at,
                ]);
            });
        }

        Schema::dropIfExists('store_items');
    }
};
