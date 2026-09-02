<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/02 §3.2 — A.4d. Bilingual because the app ships en + hi (E.5a).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name_en', 80);
            $table->string('name_hi', 80)->nullable();
            $table->string('icon_url', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_categories');
    }
};
