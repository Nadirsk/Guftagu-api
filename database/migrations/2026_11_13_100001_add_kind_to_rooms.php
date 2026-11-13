<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A room is one of three things: a voice room (Party), a solo live video, or a Video Party
 * (a grid of seats that each carry a camera). They share seats, chat, gifts and the WebRTC
 * signalling, so this is a column on `rooms` rather than a second table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('kind', 16)->default('voice')->after('room_code')->index();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
