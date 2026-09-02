<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C.3a — "a report claimed by one Moderator is not actionable by another until released."
 *
 * A claim is not an assignment. `assigned_to` is a supervisor saying "you handle this";
 * a claim is a moderator saying "I am looking at this right now", and it is what stops two
 * people banning the same user twice over the same report.
 *
 * `claimed_at` exists so the claim can **expire**. A moderator who claims a report and
 * closes their laptop must not lock it forever, and the expiry is derived from this column
 * at read time rather than cleared by a job — the same rule the rest of this codebase
 * follows for time-bounded state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('claimed_by')->nullable()->after('assigned_at')
                ->constrained('admin_users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable()->after('claimed_by');

            $table->index(['claimed_by', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign(['claimed_by']);
            $table->dropIndex(['claimed_by', 'claimed_at']);
            $table->dropColumn(['claimed_by', 'claimed_at']);
        });
    }
};
