<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic D.7c (docs/02 §7) — one row per day a user actually claims. The reward columns are
 * a snapshot of `checkin_rewards` at claim time (not a foreign key), so a later change to
 * the catalogue never rewrites what someone already received — the same reasoning the
 * ledger tables (docs/02 §15) already apply to money.
 *
 * The unique constraint is what makes "already claimed today" a query rather than a flag
 * to keep in sync, and what CheckinService reads backwards from to derive the current
 * streak — see its docblock for the exact rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('checkin_date');
            $table->unsignedTinyInteger('streak_day');   // 1-7, the day claimed, not the calendar day
            $table->string('reward_type', 20);
            $table->unsignedBigInteger('reward_value');
            $table->timestamps();

            $table->unique(['user_id', 'checkin_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_checkins');
    }
};
