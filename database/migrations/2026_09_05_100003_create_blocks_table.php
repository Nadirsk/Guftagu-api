<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §12 — D.9c.
 *
 * A block is **directional as a record and symmetric as a rule**: only the blocker's row
 * exists, but neither side may DM, call, gift or see the other. Every enforcement point
 * therefore asks the question from both ends, which is why the reverse direction gets its
 * own index rather than relying on the unique one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['blocker_id', 'blocked_id']);
            $table->index(['blocked_id', 'blocker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocks');
    }
};
