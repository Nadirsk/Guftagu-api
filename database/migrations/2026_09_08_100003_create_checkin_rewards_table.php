<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic D.7c (docs/02 §7) — the admin-configured 7-day reward ladder. One row per
 * `streak_day`, so the catalogue is exactly the 7 slots a check-in cycle can land on.
 *
 * `reward_type` is a free string(20) rather than a DB enum, matching `event_rewards` —
 * {@see CheckinReward::TYPES} is the source of truth and is deliberately narrower than
 * that sibling table's for now (coins/diamonds only; cosmetic types are a later pass,
 * once an inventory table exists to actually deliver them into).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkin_rewards', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('streak_day')->unique();  // 1-7
            $table->string('reward_type', 20);                    // coins diamonds
            $table->unsignedBigInteger('reward_value');
            $table->string('icon_url', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkin_rewards');
    }
};
