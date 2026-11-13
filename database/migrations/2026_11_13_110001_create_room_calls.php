<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Private calls inside a live video: the host's prices and the requests viewers make. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedInteger('call_voice_price')->default(0)->after('kind');
            $table->unsignedInteger('call_video_price')->default(0)->after('call_voice_price');
            $table->boolean('call_sound')->default(false)->after('call_video_price');
        });

        Schema::create('room_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('caller_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 8);                       // voice | video
            $table->unsignedInteger('price')->default(0);    // coins, fixed when the request is made
            $table->string('status', 12)->default('applying'); // applying | ongoing | ended | cancelled | rejected
            $table->unsignedInteger('seat_number')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['room_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_calls');
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['call_voice_price', 'call_video_price', 'call_sound']);
        });
    }
};
