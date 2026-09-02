<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §6 — A.6a/b. ⚠ CI-06: artwork and animations are a client input.
 *
 * `stock` is nullable on purpose: NULL means unlimited, 0 means sold out. A default of 0
 * would silently make every ordinary gift unavailable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gifts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name_en', 80);
            $table->string('name_hi', 80)->nullable();
            $table->foreignId('category_id')->nullable()->constrained('gift_categories')->nullOnDelete();
            $table->string('tier', 20)->default('basic');    // basic premium luxury legendary

            $table->unsignedBigInteger('coin_price');
            $table->unsignedBigInteger('diamond_value');     // what the receiver earns

            $table->string('thumbnail_url', 500)->nullable();
            $table->string('animation_url', 500)->nullable();
            $table->string('animation_type', 10)->nullable(); // lottie svga mp4
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('is_fullscreen')->default(false);
            $table->boolean('is_combo_enabled')->default(true);
            $table->unsignedSmallInteger('max_combo')->default(99);

            $table->foreignId('required_vip_tier_id')->nullable()->constrained('vip_tiers')->nullOnDelete();

            $table->boolean('is_limited')->default(false);
            $table->unsignedInteger('stock')->nullable();     // NULL = unlimited, 0 = sold out
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_to')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index(['category_id', 'is_active']);
            $table->index(['is_limited', 'stock']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gifts');
    }
};
