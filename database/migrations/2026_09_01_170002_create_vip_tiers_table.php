<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §7 — A.6c. ⚠ CI-02: real pricing is a client input, so these are placeholders
 * until it lands. Configurable from day one was the point (docs/00 §7).
 *
 * Prices are integer paise — never rupees as a float (docs/02 §15 rule 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vip_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('level')->unique();
            $table->string('name_en', 80);
            $table->string('name_hi', 80)->nullable();
            $table->string('badge_url', 500)->nullable();
            $table->string('frame_url', 500)->nullable();
            $table->unsignedBigInteger('entrance_effect_id')->nullable();

            $table->unsignedBigInteger('monthly_price_paise')->default(0);
            $table->unsignedBigInteger('quarterly_price_paise')->default(0);
            $table->unsignedBigInteger('yearly_price_paise')->default(0);
            $table->unsignedBigInteger('coin_price')->default(0);

            $table->json('privileges')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vip_tiers');
    }
};
