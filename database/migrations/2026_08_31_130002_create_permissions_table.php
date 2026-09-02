<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// GFT-114 — docs/02 §2.4, catalogue in docs/01 §5.4.
// Granting a `high` risk_level permission requires MFA re-entry (GFT-122).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();     // e.g. rooms.force_close
            $table->string('module', 50);
            $table->string('action', 50);
            $table->string('name', 150);
            $table->string('description', 255)->nullable();
            $table->enum('risk_level', ['low', 'medium', 'high'])->default('low');
            $table->timestamps();

            $table->index(['module', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
