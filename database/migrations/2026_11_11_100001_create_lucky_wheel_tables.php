<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lucky Wheel (room section 575:3421) — two wheels, Classic and Glory, each with exactly
 * eight admin-configured slots. A slot pays coins, diamonds or a store cosmetic, and its
 * `weight` is its share of the draw (weight / sum of the wheel's active weights).
 *
 * `lucky_wheel_spins` keeps one row per spin with the prize copied in, so an admin editing
 * a slot later does not rewrite anyone's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lucky_wheels', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 20)->unique();                   // classic | glory
            $table->string('name', 60);
            $table->unsignedInteger('spin_cost');                   // coins for one spin
            $table->unsignedTinyInteger('multi_count')->default(10);
            $table->unsignedInteger('multi_cost');                  // coins for multi_count spins
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('lucky_wheel_prizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lucky_wheel_id')->constrained('lucky_wheels')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');                // 1-8, clockwise from the top
            $table->string('label', 40)->nullable();
            $table->string('reward_type', 20);                      // coins | diamonds | a StoreItem type
            $table->unsignedBigInteger('reward_value')->default(0);
            $table->foreignId('store_item_id')->nullable()->constrained('store_items')->nullOnDelete();
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->string('icon_url', 500)->nullable();
            $table->unsignedInteger('weight')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['lucky_wheel_id', 'position']);
        });

        Schema::create('lucky_wheel_spins', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch');                                  // one tap of "1 time" / "10 times"
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lucky_wheel_id')->constrained('lucky_wheels');
            $table->foreignId('prize_id')->nullable()->constrained('lucky_wheel_prizes')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->unsignedInteger('cost');                        // coins this one spin cost
            $table->unsignedTinyInteger('position');
            $table->string('label', 40)->nullable();
            $table->string('reward_type', 20);
            $table->unsignedBigInteger('reward_value')->default(0);
            $table->foreignId('store_item_id')->nullable()->constrained('store_items')->nullOnDelete();
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'id']);
            $table->index(['lucky_wheel_id', 'created_at']);
        });

        $this->seedDefaults();
    }

    /** Coins-only starting wheels that pay back about 75% of what is spent; admins tune from here. */
    protected function seedDefaults(): void
    {
        $now = now();
        // Clockwise from the top; small and big prizes alternate round the wheel.
        $weights = [400, 8, 250, 30, 150, 2, 100, 60];
        $wheels = [
            ['mode' => 'classic', 'name' => 'Classic', 'spin_cost' => 99, 'multi_cost' => 990, 'prizes' => [10, 1000, 30, 500, 60, 5000, 99, 200]],
            ['mode' => 'glory', 'name' => 'Glory', 'spin_cost' => 999, 'multi_cost' => 9990, 'prizes' => [100, 10000, 300, 5000, 600, 50000, 999, 2000]],
        ];

        foreach ($wheels as $w) {
            $id = DB::table('lucky_wheels')->insertGetId([
                'mode' => $w['mode'], 'name' => $w['name'], 'spin_cost' => $w['spin_cost'],
                'multi_count' => 10, 'multi_cost' => $w['multi_cost'], 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach ($w['prizes'] as $i => $coins) {
                DB::table('lucky_wheel_prizes')->insert([
                    'lucky_wheel_id' => $id, 'position' => $i + 1, 'label' => null,
                    'reward_type' => 'coins', 'reward_value' => $coins, 'weight' => $weights[$i],
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lucky_wheel_spins');
        Schema::dropIfExists('lucky_wheel_prizes');
        Schema::dropIfExists('lucky_wheels');
    }
};
