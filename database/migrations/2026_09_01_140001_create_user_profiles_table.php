<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/02 §2.2 (D.1b–d)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name', 50);
            $table->string('avatar_url', 500)->nullable();
            $table->string('cover_url', 500)->nullable();
            $table->string('bio', 300)->nullable();
            $table->string('gender', 20)->default('undisclosed');
            $table->date('date_of_birth')->nullable();      // 18+ enforced at signup
            $table->string('country', 80)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('language', 5)->default('en');
            $table->string('theme', 10)->default('system');
            $table->json('privacy')->nullable();
            $table->json('notification_prefs')->nullable();
            $table->boolean('is_profile_complete')->default(false);
            $table->timestamps();

            $table->index('display_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
