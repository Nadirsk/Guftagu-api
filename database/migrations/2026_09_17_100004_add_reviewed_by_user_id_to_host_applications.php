<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D.9a — an agency owner (a regular user, not an admin) can review applications to
 * their own agency. `reviewed_by` stays admin-only; this column records the owner's
 * review separately so the two are never confused in the trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('host_applications', function (Blueprint $table) {
            $table->foreignId('reviewed_by_user_id')->nullable()->after('reviewed_by')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('host_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by_user_id');
        });
    }
};
