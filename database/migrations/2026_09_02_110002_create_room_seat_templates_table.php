<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed, reusable seat layouts — "12 seats, 2 of them VIP, at positions 1 and 2" —
 * the same catalogue-before-the-app-needs-it pattern as room_categories/room_themes
 * (docs/02 §3.2's comment on RoomCatalogueController: "the app cannot offer categories or
 * themes that were never defined, so the catalogue has to come first").
 *
 * A room's own `seat_count` stays a plain integer the host sets at creation (mobile-app
 * scope, not yet built) — this table does not constrain it. It exists so that flow has
 * ready-made options to offer instead of a free-typed number, and so the positions that
 * default to VIP are defined once rather than re-typed per room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_seat_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->unsignedTinyInteger('total_seats');
            // Seat numbers that default to VIP, e.g. [1, 2]. Null/empty = no VIP seats.
            $table->json('vip_positions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_seat_templates');
    }
};
