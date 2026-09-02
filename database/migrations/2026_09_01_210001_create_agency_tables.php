<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §10 — agencies, hosts and what they are owed (epic A.8, B.2).
 *
 * Every money column here is **paise, as an integer**, and every rate is integer basis
 * points, per docs/02 §15. A settlement is the one place three parties split one number,
 * so the columns are stored side by side and their sum is asserted rather than assumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 20)->unique();            // human-quotable: AGY-0001
            $table->string('name', 120);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('logo_url', 500)->nullable();
            $table->text('description')->nullable();
            // Encrypted at rest like every other contact detail, with hashes for lookup.
            $table->text('contact_phone')->nullable();
            $table->string('contact_phone_hash', 64)->nullable()->index();
            $table->text('contact_email')->nullable();
            $table->string('contact_email_hash', 64)->nullable()->index();
            $table->json('documents')->nullable();           // [{type, url, uploaded_at}]
            $table->unsignedInteger('commission_bp')->default(0);
            $table->string('status', 20)->default('pending'); // pending approved suspended rejected
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('managed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
        });

        Schema::create('hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->string('status', 20)->default('pending'); // pending approved suspended rejected left
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('tier', 20)->nullable();
            $table->unsignedInteger('base_commission_bp')->default(0);
            $table->date('contract_start')->nullable();
            $table->date('contract_end')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'status']);
            $table->index(['status']);
        });

        Schema::create('agency_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20)->default('host');     // owner manager host
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Somebody may rejoin an agency later, so this is not unique on (agency, user)
            // — the history is the point.
            $table->index(['agency_id', 'is_active']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('host_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->string('intro_audio_url', 500)->nullable();
            $table->text('experience')->nullable();
            $table->string('status', 20)->default('pending'); // pending approved rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('host_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('hosts')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('target_diamonds')->default(0);
            $table->unsignedInteger('target_hours')->default(0);
            $table->unsignedInteger('target_days')->default(0);
            // The evaluated snapshot, written once the period closes. While the period is
            // open these stay null and the API derives the live figures from host_earnings
            // — a stale column would otherwise be reported as this month's progress.
            $table->unsignedBigInteger('achieved_diamonds')->nullable();
            $table->unsignedInteger('achieved_hours')->nullable();
            $table->unsignedInteger('achieved_days')->nullable();
            $table->unsignedInteger('achievement_pct')->nullable();   // whole percent
            $table->unsignedBigInteger('incentive_paise')->nullable();
            $table->unsignedInteger('incentive_bp')->nullable();      // the slab that applied
            $table->string('status', 20)->default('active');          // active achieved missed cancelled
            $table->timestamp('evaluated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['host_id', 'period_start']);
            $table->index(['status', 'period_end']);
        });

        Schema::create('host_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('hosts')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('diamonds_earned')->default(0);
            $table->unsignedBigInteger('gross_paise')->default(0);
            $table->unsignedBigInteger('platform_cut_paise')->default(0);
            $table->unsignedBigInteger('agency_cut_paise')->default(0);
            $table->unsignedBigInteger('net_paise')->default(0);
            $table->unsignedInteger('room_hours')->default(0);
            $table->unsignedInteger('gift_count')->default(0);
            // Null, not zero: the sender is not on the diamond ledger, so this is
            // uncountable until gift_transactions lands with D.1. Zero would read as
            // "nobody gifted them", which is a different and false statement.
            $table->unsignedInteger('unique_gifters')->nullable();
            $table->timestamps();

            $table->unique(['host_id', 'date']);
            $table->index(['date']);
        });

        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('gross_diamonds')->default(0);
            $table->unsignedBigInteger('gross_paise')->default(0);
            $table->unsignedBigInteger('platform_cut_paise')->default(0);
            $table->unsignedBigInteger('agency_cut_paise')->default(0);
            $table->unsignedBigInteger('host_cut_paise')->default(0);
            $table->unsignedBigInteger('net_payable_paise')->default(0);
            // The rate used, frozen on the row. Approving next week must still settle at
            // the price that applied during the period — same rule as withdrawals.
            $table->unsignedBigInteger('rate_numerator')->nullable();
            $table->unsignedBigInteger('rate_denominator')->nullable();
            $table->unsignedInteger('host_count')->default(0);
            $table->string('status', 20)->default('draft');  // draft manager_raised admin_approved paid rejected
            $table->foreignId('raised_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('batch_id')->nullable()->constrained('payout_batches')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            // One settlement per agency per period. Regenerating replaces the draft rather
            // than quietly creating a second claim on the same money.
            $table->unique(['agency_id', 'period_start']);
            $table->index(['status', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('host_earnings');
        Schema::dropIfExists('host_targets');
        Schema::dropIfExists('host_applications');
        Schema::dropIfExists('agency_members');
        Schema::dropIfExists('hosts');
        Schema::dropIfExists('agencies');
    }
};
