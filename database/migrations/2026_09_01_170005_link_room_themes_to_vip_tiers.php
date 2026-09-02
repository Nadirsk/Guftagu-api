<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the loop left open in A.4.
 *
 * `room_themes.required_vip_tier_id` was added without a foreign key because `vip_tiers`
 * did not exist yet — the migration said so at the time. It exists now, so the constraint
 * goes on.
 *
 * The seeded themes stored a VIP *level* (2, 3) in a column that means a tier *id*. Those
 * two only coincide by luck, so the values are remapped through `vip_tiers.level` before
 * the constraint is added; anything that cannot be resolved is nulled rather than left to
 * fail the FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('room_themes') || ! Schema::hasTable('vip_tiers')) {
            return;
        }

        $idByLevel = DB::table('vip_tiers')->pluck('id', 'level');

        foreach (DB::table('room_themes')->whereNotNull('required_vip_tier_id')->get() as $theme) {
            DB::table('room_themes')
                ->where('id', $theme->id)
                ->update(['required_vip_tier_id' => $idByLevel[$theme->required_vip_tier_id] ?? null]);
        }

        Schema::table('room_themes', function (Blueprint $table) {
            $table->foreign('required_vip_tier_id')->references('id')->on('vip_tiers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('room_themes', function (Blueprint $table) {
            $table->dropForeign(['required_vip_tier_id']);
        });
    }
};
