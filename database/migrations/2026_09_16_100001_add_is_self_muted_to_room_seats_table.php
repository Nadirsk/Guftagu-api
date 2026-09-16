<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/03 §177 — `PATCH /rooms/{uuid}/mic` self mute/unmute. Separate from
 * `is_muted_by_host` (host/moderator action, docs/02 §3.2): a seat can be silenced by
 * either party independently, and unmuting has to require whichever one muted it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_seats', function (Blueprint $table) {
            $table->boolean('is_self_muted')->default(false)->after('is_muted_by_host');
        });
    }

    public function down(): void
    {
        Schema::table('room_seats', function (Blueprint $table) {
            $table->dropColumn('is_self_muted');
        });
    }
};
