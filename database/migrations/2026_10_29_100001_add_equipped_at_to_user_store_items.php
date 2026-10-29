<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which owned item of each type the user is wearing. A timestamp rather than a boolean so
 * "the one equipped most recently" is a single ORDER BY if two ever end up set; the store
 * keeps it to one per type on write (StoreController::equip).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_store_items', function (Blueprint $table) {
            $table->timestamp('equipped_at')->nullable()->after('expires_at');
            $table->index(['user_id', 'equipped_at']);
        });
    }

    public function down(): void
    {
        Schema::table('user_store_items', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'equipped_at']);
            $table->dropColumn('equipped_at');
        });
    }
};
