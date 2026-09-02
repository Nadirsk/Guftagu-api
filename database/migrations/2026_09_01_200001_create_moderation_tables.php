<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §11 — A.5, C.3, C.4.
 *
 * `user_sanctions` and `moderation_logs` already exist from A.3 and A.4; this adds the
 * reports queue, the filter word list, and the flag trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_type', 20);      // user room message post profile
            $table->unsignedBigInteger('target_id');
            $table->string('category', 30);         // abuse nudity harassment spam fraud underage other
            $table->text('description')->nullable();
            $table->json('evidence_urls')->nullable();
            $table->string('audio_clip_url', 500)->nullable();
            $table->string('priority', 10)->default('medium');  // low medium high critical
            $table->string('status', 20)->default('open');      // open assigned actioned dismissed escalated

            $table->foreignId('assigned_to')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->foreignId('escalated_to')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('escalated_at')->nullable();

            $table->timestamps();

            // docs/02 §14 names this one: the moderation queue's hot index.
            $table->index(['status', 'priority', 'created_at']);
            $table->index(['assigned_to', 'status']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('report_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('action', 30);           // warn mute kick ban_temp ban_permanent content_remove room_close dismiss escalate
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('note', 500)->nullable();
            // A.5c — an action an Admin later undid counts against the moderator's
            // reversal rate, so the link has to be recorded at the moment it is reversed.
            $table->foreignId('reversed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['admin_user_id', 'created_at']);
            $table->index(['report_id', 'created_at']);
        });

        Schema::create('banned_words', function (Blueprint $table) {
            $table->id();
            $table->string('word', 191);
            $table->string('language', 5)->default('en');
            $table->string('severity', 10)->default('block');   // block flag replace
            $table->string('replacement', 50)->nullable();
            $table->json('scope')->nullable();                  // room_name chat bio dm
            $table->boolean('is_regex')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['word', 'language']);
            $table->index(['is_active', 'severity']);
        });

        Schema::create('content_flags', function (Blueprint $table) {
            $table->id();
            $table->string('content_type', 40);     // chat room_name bio dm
            $table->string('content_id', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('flagged_by', 20)->default('system');  // system user moderator
            $table->string('rule_matched', 191)->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('status', 20)->default('open');        // open reviewed dismissed
            $table->foreignId('reviewed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['content_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_flags');
        Schema::dropIfExists('banned_words');
        Schema::dropIfExists('report_actions');
        Schema::dropIfExists('reports');
    }
};
