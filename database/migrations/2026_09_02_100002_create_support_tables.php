<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §13 — support tickets (epic B.4, D.9d).
 *
 * The SLA columns are the reason this table has more than the obvious shape.
 *
 * B.4a is about a **first-response timer** that stops when a Manager replies, and B.4c is
 * about a ticket breaching its SLA and being escalated. Both need the moment of first
 * staff reply recorded once and never overwritten — `first_response_at` is set on the
 * first outbound message and left alone afterwards, because "how long until somebody
 * answered" is a fact about the past, not a rolling figure.
 *
 * `sla_first_response_minutes` and `sla_resolution_minutes` are **copied onto the row** at
 * creation rather than read from settings at report time. A ticket has to be judged against
 * the promise that applied when it was raised; changing the policy next month must not
 * retroactively put last month's tickets in breach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('ref', 20)->unique();               // human-quotable: TKT-000123
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 40)->default('other');
            $table->string('subject', 200);
            $table->text('description');
            $table->json('attachments')->nullable();
            $table->string('priority', 20)->default('medium');  // low medium high urgent
            $table->string('status', 20)->default('open');      // open pending resolved closed
            $table->foreignId('assigned_to')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            // Set once, on the first staff reply. Never rewritten.
            $table->timestamp('first_response_at')->nullable();
            $table->unsignedInteger('sla_first_response_minutes')->default(240);
            $table->unsignedInteger('sla_resolution_minutes')->default(2880);

            $table->foreignId('escalated_to')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('escalated_at')->nullable();
            $table->string('escalation_note', 500)->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('resolution', 1000)->nullable();
            $table->timestamps();

            $table->index(['status', 'priority', 'created_at']);
            $table->index(['assigned_to', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            // Who wrote it. `user` is the person who raised it, `admin` is staff, `system`
            // is a status change worth showing in the thread (escalation, assignment).
            $table->string('sender_type', 10);                  // user admin system
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->text('body');
            $table->json('attachments')->nullable();
            // An internal note is visible to staff and never to the person who raised the
            // ticket. Mixing the two in one table with no flag is how a private note ends
            // up in somebody's inbox.
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
        });

        Schema::create('canned_replies', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('category', 40)->default('general');
            $table->text('body_en');
            $table->text('body_hi')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_replies');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
