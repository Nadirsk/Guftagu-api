<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §5.4 — A.7c, GFT-068. ⚠ CI-02.
 *
 * `percentage_bp` is **integer basis points** (1250 = 12.50%). docs/02 says it plainly:
 * "A float rate is how you lose a rupee per thousand transactions and cannot explain
 * where it went."
 *
 * `max_value` NULL means "and above" — the open-ended top slab.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_slabs', function (Blueprint $table) {
            $table->id();
            $table->string('applies_to', 20);                // platform agency host
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->string('metric', 30);                    // diamonds_earned coins_spent
            $table->unsignedBigInteger('min_value')->default(0);
            $table->unsignedBigInteger('max_value')->nullable();   // NULL = and above
            $table->unsignedInteger('percentage_bp');
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['applies_to', 'metric', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_slabs');
    }
};
