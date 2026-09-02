<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// GFT-114 — docs/02 §2.4. Append-only: never updated, never deleted.
// No updated_at, by design — a row here is a historical fact.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_grants_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('target_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('permission_id')->nullable()->constrained('permissions')->nullOnDelete();
            $table->enum('action', ['grant', 'revoke', 'scope_change', 'deny']);
            $table->string('effect_before', 20)->nullable();  // allow | deny | null (absent)
            $table->string('effect_after', 20)->nullable();
            $table->json('scope')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['target_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_grants_log');
    }
};
