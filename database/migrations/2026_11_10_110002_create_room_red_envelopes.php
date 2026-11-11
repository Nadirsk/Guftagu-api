<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_red_envelopes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('total_coins');
            $table->unsignedSmallInteger('total_count');
            $table->unsignedBigInteger('remaining_coins');
            $table->unsignedSmallInteger('remaining_count');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['room_id', 'remaining_count']);
        });

        Schema::create('room_red_envelope_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained('room_red_envelopes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->timestamps();

            // One share per person per envelope — also what makes a double tap harmless.
            $table->unique(['envelope_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_red_envelope_claims');
        Schema::dropIfExists('room_red_envelopes');
    }
};
