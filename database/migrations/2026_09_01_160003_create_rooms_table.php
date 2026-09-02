<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §3.1 — D.2a, A.4.
 *
 * `listener_count` is denormalised from Redis every 10 s, so it is a recent number rather
 * than a live one. The admin list orders by it (docs/02 §14: "Explore — live public rooms
 * by popularity"), which is why it carries its own descending index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('room_code', 12)->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('room_categories')->nullOnDelete();
            $table->foreignId('theme_id')->nullable()->constrained('room_themes')->nullOnDelete();

            $table->string('name', 80);
            $table->string('description', 300)->nullable();
            $table->string('cover_url', 500)->nullable();
            $table->string('announcement', 500)->nullable();
            $table->string('visibility', 10)->default('public');
            $table->string('password_hash')->nullable();

            $table->unsignedTinyInteger('seat_count')->default(8);
            $table->string('seat_layout', 20)->default('classic');
            $table->boolean('video_enabled')->default(false);

            $table->string('status', 20)->default('idle');   // live idle closed force_closed
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('featured_until')->nullable();

            $table->unsignedInteger('listener_count')->default(0);
            $table->unsignedInteger('peak_listeners')->default(0);
            $table->unsignedBigInteger('total_diamonds_received')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('close_reason', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'is_featured']);
            $table->index(['category_id', 'status']);
            $table->index(['status', 'listener_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
