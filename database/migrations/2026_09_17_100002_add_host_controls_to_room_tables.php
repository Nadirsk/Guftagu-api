<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D.2b — the room owner/co-host's own mute, distinct from `is_muted_by_host`
 * (`RoomModerationService::mute` — a staff sanction, audited, with a reason and an
 * optional expiry). Reusing that column for a host's casual in-room mute would mean a
 * host's own "unmute" could silently undo a moderator's sanction with no reason, no audit
 * row and no expiry check. `RoomSeat::isEffectivelyMuted()` ORs both in.
 *
 * `hand_raised_at` (on `room_members`, not `room_seats`) — raise-hand happens before a
 * seat exists, from the floor. A timestamp rather than a boolean so the host can sort the
 * queue by who asked first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_seats', function (Blueprint $table) {
            $table->boolean('is_muted_by_owner')->default(false)->after('is_muted_by_host');
        });

        Schema::table('room_members', function (Blueprint $table) {
            $table->timestamp('hand_raised_at')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('room_seats', function (Blueprint $table) {
            $table->dropColumn('is_muted_by_owner');
        });

        Schema::table('room_members', function (Blueprint $table) {
            $table->dropColumn('hand_raised_at');
        });
    }
};
