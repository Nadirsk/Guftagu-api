<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/00 §? — "Wealth (top gifters) and charm (top hosts) ranking systems", and docs/02
 * §2's `lifetime_coins_spent` / `lifetime_diamonds_earned` ("drives wealth level" /
 * "drives charm level" — those columns existed with no ladder to read them against).
 *
 * This is the admin-configurable ladder only (mirrors `ranking_rules` / `vip_tiers`).
 * A user's current level is derived at read time from the wallet's lifetime counters
 * against this table, the same way a sanction or a featured window derives its state —
 * nothing writes a level onto a user. The mobile-facing progression engine that detects
 * a level-up and broadcasts it (GFT-281/282, epic D.7, layer BE/APP) is not this: that is
 * the app's own backend, out of admin-panel scope for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wealth_charm_levels', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);           // wealth | charm
            $table->unsignedSmallInteger('level');
            $table->string('name_en', 80);
            $table->string('name_hi', 80)->nullable();
            // Coins spent (wealth) or diamonds earned (charm) needed to reach this level.
            $table->unsignedBigInteger('threshold');
            $table->string('badge_url', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'level']);
            $table->index(['type', 'is_active', 'threshold']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wealth_charm_levels');
    }
};
