<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/02 §3.2 — A.4d.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_themes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('background_url', 500)->nullable();
            $table->string('preview_url', 500)->nullable();
            $table->boolean('is_premium')->default(false);

            // No FK: vip_tiers arrives with A.6 (M4). Storing the id now lets the panel
            // set the gate; the mobile app enforces VIP_TIER_REQUIRED when both exist.
            $table->unsignedBigInteger('required_vip_tier_id')->nullable();

            $table->unsignedBigInteger('coin_price')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'is_premium']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_themes');
    }
};
