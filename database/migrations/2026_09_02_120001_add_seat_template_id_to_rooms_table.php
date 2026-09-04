<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The link the room-catalogue side was missing: which seat template a room is actually
 * using. Assigning one (RoomService::setSeatTemplate) applies its `vip_positions` onto
 * the room's own `room_seats.is_vip` — a one-time bulk write, not a live binding, so a
 * moderator's later per-seat override (POST .../seats/{seat}/vip) is never silently
 * overwritten just because the template row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->foreignId('seat_template_id')->nullable()
                ->after('seat_layout')
                ->constrained('room_seat_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seat_template_id');
        });
    }
};
