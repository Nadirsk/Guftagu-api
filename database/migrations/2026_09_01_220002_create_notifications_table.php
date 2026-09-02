<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §13 — the in-app inbox (A.10a, E.2).
 *
 * Named `notifications` per docs/02. Laravel's own notification table is not in use here,
 * so there is no clash; if it ever is, this is the one the product means.
 *
 * A broadcast writes one row per recipient. That is deliberate: a shared row with a
 * read-marker join is cheaper to write and far more expensive to read, and the read path
 * is the one that runs on every app open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title', 200);
            $table->text('body');
            $table->json('data')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->string('deep_link', 500)->nullable();
            $table->string('channel', 20)->default('in_app');  // push in_app sms email
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_read', 'created_at']);
            $table->index(['admin_user_id', 'is_read', 'created_at']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
