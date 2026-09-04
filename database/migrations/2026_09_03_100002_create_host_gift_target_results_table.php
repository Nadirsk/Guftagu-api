<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per host per calendar month. `coins_sent`/`minutes_live` are read-time totals
 * until evaluated; evaluating freezes them plus whichever policy tier was achieved and
 * the reward amounts it paid — same freeze-on-evaluate split as `host_targets`
 * (docs: derive what is still changing, freeze what has been decided).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_gift_target_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('hosts')->cascadeOnDelete();
            $table->char('period', 7); // 'YYYY-MM'
            $table->unsignedBigInteger('coins_sent')->default(0);
            $table->unsignedInteger('minutes_live')->default(0);
            $table->foreignId('policy_id')->nullable()->constrained('gift_target_policies')->nullOnDelete();
            $table->unsignedBigInteger('host_reward_paise')->default(0);
            $table->unsignedBigInteger('agency_reward_paise')->default(0);
            $table->timestamp('evaluated_at')->nullable();
            $table->foreignId('evaluated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['host_id', 'period']);
            $table->index(['period', 'policy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_gift_target_results');
    }
};
