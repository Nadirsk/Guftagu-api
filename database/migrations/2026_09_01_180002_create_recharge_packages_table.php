<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/02 §5.3 — A.7a, GFT-067. ⚠ CI-01. Prices in integer paise.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recharge_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->unsignedBigInteger('coins');
            $table->unsignedBigInteger('bonus_coins')->default(0);
            $table->unsignedBigInteger('price_paise');
            $table->string('currency', 3)->default('INR');
            $table->boolean('is_first_purchase_only')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('badge_text', 40)->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recharge_packages');
    }
};
