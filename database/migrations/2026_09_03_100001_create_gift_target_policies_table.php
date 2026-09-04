<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mehfil's "Policies" screen (time / target value / host salary / agent salary), ported
 * to Guftagoo's own units. This is deliberately a **separate** feature from
 * `host_targets` (A.8b, GFT-082/083): that one is a bespoke per-host target an admin
 * sets for a custom period, paying only the host, sized as a percentage slab of their
 * diamond earnings. This one is a shared ladder ANY host qualifies into automatically
 * each month by crossing a flat coins-sent + minutes-live threshold, paying both the
 * host and their agency a flat amount. Different metric, different payees, different
 * shape — merging them would make either one impossible to reason about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_target_policies', function (Blueprint $table) {
            $table->id();
            // Monthly cumulative thresholds — both must be cleared to achieve this tier.
            $table->unsignedInteger('time_minutes');
            $table->unsignedBigInteger('target_coins');
            $table->unsignedBigInteger('host_reward_paise')->default(0);
            $table->unsignedBigInteger('agency_reward_paise')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'target_coins']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_target_policies');
    }
};
