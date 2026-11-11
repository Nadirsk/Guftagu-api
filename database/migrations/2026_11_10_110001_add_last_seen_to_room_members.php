<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            // Heartbeat: the app polls room state every few seconds while open. A member whose
            // heartbeat went quiet has gone (app closed, phone locked, minimised for long) and
            // coming back counts as a fresh arrival, so they never see the chat from their absence.
            $table->timestamp('last_seen_at')->nullable()->after('joined_at');
        });
    }

    public function down(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
