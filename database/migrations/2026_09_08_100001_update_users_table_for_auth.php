<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic D.1a — email/password sign-in and Google/Facebook linking widen the account model
 * beyond "every account has a phone" (docs/04 D.1a originally spec'd phone-OTP + social
 * only). `phone`/`phone_hash` become nullable so an email-only or social-only signup does
 * not need a placeholder number; `google_id`/`facebook_id` are how a social login resolves
 * to an existing account without a join table — two providers do not earn one.
 *
 * `referred_by_user_id` records the "Invite code" field from the profile-setup screen: the
 * code entered is another user's `guftagu_id`, resolved to their row at signup time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('phone')->nullable()->change();
            $table->char('phone_hash', 64)->nullable()->change();

            $table->string('google_id', 64)->nullable()->unique()->after('email_hash');
            $table->string('facebook_id', 64)->nullable()->unique()->after('google_id');

            $table->foreignId('referred_by_user_id')->nullable()->after('facebook_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_user_id');
            $table->dropColumn(['google_id', 'facebook_id']);
            $table->text('phone')->nullable(false)->change();
            $table->char('phone_hash', 64)->nullable(false)->change();
        });
    }
};
