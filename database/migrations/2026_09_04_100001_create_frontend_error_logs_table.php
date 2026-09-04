<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// IT Admin epic — append-only, like audit_logs. `admin_user_id` is nullable because a
// report can arrive from a session whose token has already expired by the time it lands.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frontend_error_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('level', 20)->default('error');
            $table->text('message');
            $table->text('stack')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at']);
            $table->index(['level', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frontend_error_logs');
    }
};
