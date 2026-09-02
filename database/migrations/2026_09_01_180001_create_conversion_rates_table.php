<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §5.3 — A.7a. ⚠ CI-01 supplies the real rates.
 *
 * Rates are a **rational pair**, never a float: `numerator / denominator`. A float rate is
 * how you lose a rupee per thousand conversions and cannot explain where it went
 * (docs/02 §15 rule 1, and the same reasoning as commission basis points).
 *
 * Effective-dated so A.7a holds: "today's withdrawals use today's rate and tomorrow's use
 * the new one — historical requests are never re-priced." Rows are never edited; a change
 * closes the current row and opens a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_rates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40);                       // coin_to_diamond | diamond_to_inr
            $table->unsignedBigInteger('rate_numerator');
            $table->unsignedBigInteger('rate_denominator');
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();   // NULL = still in force
            $table->foreignId('set_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            // The lookup is always "the row for this key in force at this moment".
            $table->index(['key', 'effective_from']);
            $table->index(['key', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_rates');
    }
};
