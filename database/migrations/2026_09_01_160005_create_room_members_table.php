<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/02 §3.2 — presence history, and the member list on the admin room detail.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20)->default('listener');   // owner co_host speaker listener
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['room_id', 'is_active']);
            $table->index(['user_id', 'joined_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_members');
    }
};
