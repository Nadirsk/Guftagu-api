<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The entitlement layer that `EventService::payReward()` and `CheckinReward::TYPES`
 * both note is missing: "frame / badge / vip_days need user_frames, user_badges and
 * user_vip_subscriptions". Cosmetic and VIP rewards have been recorded but never
 * actually granted anywhere in the app until these tables exist.
 *
 * Rows are append-style, not a single mutable "current state" column, so a grant never
 * discards history — {@see \App\Domain\Store\InventoryService} decides whether a repeat
 * grant extends the most recent row or starts a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_vip_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vip_tier_id')->constrained('vip_tiers')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('expires_at');
            $table->string('source', 20)->default('purchase'); // purchase event admin
            $table->timestamps();

            // "Current" VIP is the latest row for a user that has not expired yet —
            // there is deliberately no unique(user_id) here, since an upgrade/downgrade
            // starts a fresh row rather than rewriting the one before it.
            $table->index(['user_id', 'expires_at']);
        });

        Schema::create('user_store_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_item_id')->constrained('store_items')->cascadeOnDelete();
            $table->dateTime('starts_at');
            // NULL means permanent ownership — see `StoreItem::isPermanent()`.
            $table->dateTime('expires_at')->nullable();
            $table->string('source', 20)->default('purchase'); // purchase vip event admin
            $table->timestamps();

            // One row per (user, item): a repeat grant of the same frame/bubble/effect
            // extends this row rather than creating a second, ambiguous ownership record.
            $table->unique(['user_id', 'store_item_id']);
            $table->index(['user_id', 'expires_at']);
        });

        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained('badges')->cascadeOnDelete();
            $table->dateTime('awarded_at')->useCurrent();
            // Most badges are permanent (earned, never expire); an event can still grant
            // one for N days — e.g. the "Medal" line in a Weekly Star bundle.
            $table->dateTime('expires_at')->nullable();
            $table->string('source', 20)->default('event'); // event admin auto
            $table->timestamps();

            $table->unique(['user_id', 'badge_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_badges');
        Schema::dropIfExists('user_store_items');
        Schema::dropIfExists('user_vip_subscriptions');
    }
};
