<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/02 §6 and §7 — A.6d. Frames, badges and entrance effects.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frames', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('image_url', 500)->nullable();
            $table->string('animation_url', 500)->nullable();
            $table->string('source', 20)->default('purchase');  // vip event purchase admin
            $table->unsignedBigInteger('coin_price')->default(0);
            $table->foreignId('required_vip_tier_id')->nullable()->constrained('vip_tiers')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'source']);
        });

        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name_en', 80);
            $table->string('name_hi', 80)->nullable();
            $table->string('icon_url', 500)->nullable();
            $table->string('description', 300)->nullable();
            $table->json('criteria')->nullable();
            $table->boolean('is_auto_awarded')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('entrance_effects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('animation_url', 500)->nullable();
            $table->string('animation_type', 10)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('trigger', 20)->default('vip_entry'); // vip_entry big_gift level_up event
            $table->foreignId('required_vip_tier_id')->nullable()->constrained('vip_tiers')->nullOnDelete();
            $table->unsignedBigInteger('min_gift_coin_value')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'trigger']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entrance_effects');
        Schema::dropIfExists('badges');
        Schema::dropIfExists('frames');
    }
};
