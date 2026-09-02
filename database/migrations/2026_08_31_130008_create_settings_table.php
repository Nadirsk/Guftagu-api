<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/02 §13 — cached in `cache:settings` (600 s). Feature flags live here.
// Drives session timeout (A.1c) and per-role 2FA enforcement (A.1d / GFT-007).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 150)->unique();
            $table->text('value')->nullable();
            $table->enum('type', ['string', 'int', 'bool', 'json'])->default('string');
            $table->string('group', 50)->default('general');
            $table->boolean('is_public')->default(false);
            $table->string('description', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
