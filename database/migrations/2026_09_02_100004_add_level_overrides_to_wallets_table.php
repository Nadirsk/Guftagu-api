<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GFT-027 — "Level and VIP override endpoints, both audit-logged" (epic A.3, layer BE).
 * This is the level half. A null override means "derive it from the wallet counters as
 * usual"; a set override wins outright, the same shape as every other derive-at-read-time
 * field in this codebase, just with an admin-supplied input alongside the computed one.
 *
 * The VIP half of GFT-027 is not built here: there is no `user_vip_subscriptions` table
 * yet (that lands with D.7a's purchase flow, mobile-app scope), so there is nothing
 * correct to override onto — building one now risks a schema that fights the real one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->foreignId('wealth_level_override_id')->nullable()
                ->after('lifetime_diamonds_earned')
                ->constrained('wealth_charm_levels')->nullOnDelete();
            $table->foreignId('charm_level_override_id')->nullable()
                ->after('wealth_level_override_id')
                ->constrained('wealth_charm_levels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wealth_level_override_id');
            $table->dropConstrainedForeignId('charm_level_override_id');
        });
    }
};
