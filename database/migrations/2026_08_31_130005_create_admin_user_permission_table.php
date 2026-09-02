<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// GFT-114 — docs/02 §2.4. Direct grants AND explicit denies.
// A `deny` row overrides the role baseline; see PermissionResolver (GFT-115).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_user_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->enum('effect', ['allow', 'deny'])->default('allow');
            $table->foreignId('granted_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();      // GFT-121
            $table->json('scope')->nullable();                // GFT-120; empty/absent = unrestricted
            $table->timestamps();

            $table->unique(['admin_user_id', 'permission_id']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_user_permission');
    }
};
