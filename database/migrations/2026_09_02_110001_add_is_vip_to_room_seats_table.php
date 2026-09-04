<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-seat VIP flag. Nothing in the schema had any notion of a VIP seat before this —
 * a room's `seat_count` is just an integer the host picked, with no concept of some seats
 * being special. This is the live, per-room-instance state; `room_seat_templates` (see its
 * own migration) is the separate, reusable "which positions are VIP by default" catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_seats', function (Blueprint $table) {
            $table->boolean('is_vip')->default(false)->after('is_locked');
        });
    }

    public function down(): void
    {
        Schema::table('room_seats', function (Blueprint $table) {
            $table->dropColumn('is_vip');
        });
    }
};
