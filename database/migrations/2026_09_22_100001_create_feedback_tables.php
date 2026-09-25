<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Me screen's Feedback form — a problem description, a required contact
 * (support's only way to follow up, since these aren't tied to a ticket
 * thread), and up to 6 photos (docs' `uniqueIds`/uuid convention applies —
 * see Feedback model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('description', 120);
            $table->string('contact', 30);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('feedback_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_id')->constrained('feedback')->cascadeOnDelete();
            // Relative to `config('filesystems.uploads_disk')` — see FeedbackController.
            $table->string('path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_images');
        Schema::dropIfExists('feedback');
    }
};
