<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §13 — A.2d / A.10c. "always a queued job".
 *
 * The row is created immediately so the caller gets an id and is not blocked; the worker
 * fills in file_path and flips status when it finishes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('type', 40);                     // revenue engagement users
            $table->json('filters')->nullable();
            $table->string('format', 10)->default('csv');
            $table->string('status', 20)->default('queued'); // queued processing ready failed
            $table->string('file_path', 500)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['admin_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
