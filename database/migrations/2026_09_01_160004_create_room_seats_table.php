<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/02 §3.2 — D.2b. The durable mirror of Redis seat state; the admin seat map reads this.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('seat_number');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_muted_by_host')->default(false);
            $table->boolean('is_camera_on')->default(false);
            $table->timestamp('occupied_at')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'seat_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_seats');
    }
};
