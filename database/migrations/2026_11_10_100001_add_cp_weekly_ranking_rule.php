<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The CP Reward tab's rewards are set in the admin Rankings page like the Rich / Charm
 * Weekly List ones, so CP needs a rule for them to hang off. It is rewards-only: CP pairs
 * and points are not tracked, so nothing is ranked or paid from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ranking_rules')->insertOrIgnore([
            'key'           => 'cp_weekly',
            'board_type'    => 'cp',
            'period'        => 'weekly',
            'metric'        => 'cp_points',
            'min_threshold' => 10_000_000, // the CP rules text: nothing below 10,000,000 points is rewarded
            'top_n'         => 10,
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('ranking_rewards')->where('rule_key', 'cp_weekly')->delete();
        DB::table('ranking_rules')->where('key', 'cp_weekly')->delete();
    }
};
