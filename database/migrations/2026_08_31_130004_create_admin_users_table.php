<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// GFT-001 — docs/02 §2.2.
// Deliberately separate from `users`: panel staff and app users share no lifecycle,
// no auth path and no threat model. Merging them is how privilege bugs get written.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 191)->unique();
            $table->string('password');                       // bcrypt cost 12 (GFT-002)
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->string('avatar_url', 500)->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('mfa_enabled')->default(false);
            $table->text('mfa_secret')->nullable();           // encrypted cast
            $table->unsignedSmallInteger('session_timeout_minutes')->nullable();
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
