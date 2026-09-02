<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/02 §13 — banners, announcements, pages, FAQs and broadcasts (epic A.10a).
 *
 * Every scheduled thing here carries `starts_at` / `ends_at` and an `is_active` flag, and
 * **visibility is derived from both at read time** — the same rule as featured rooms, gift
 * drops and event phases. A.10a asks that a banner scheduled 01–07 September be invisible
 * before the 1st and hidden after the 7th "with no manual step"; a job that flipped
 * `is_active` on a schedule would strand banners live whenever the scheduler stalled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->string('image_url', 500);
            $table->string('placement', 30);                  // home_top room_list wallet event
            $table->string('action_type', 30)->nullable();    // url room event none
            $table->string('action_value', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            // A.10a wants clicks counted per placement. The placement lives on the row, so
            // a plain counter is enough — grouping happens at read time.
            $table->unsignedBigInteger('click_count')->default(0);
            $table->unsignedBigInteger('impression_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['placement', 'is_active', 'sort_order']);
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title_en', 200);
            $table->string('title_hi', 200)->nullable();
            $table->text('body_en');
            $table->text('body_hi')->nullable();
            $table->string('type', 20)->default('marquee');   // marquee popup banner
            $table->json('target_roles')->nullable();         // null/empty = everyone
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('title_en', 200);
            $table->string('title_hi', 200)->nullable();
            $table->longText('content_en');
            $table->longText('content_hi')->nullable();
            $table->string('type', 20)->default('help');      // terms privacy faq about guidelines help
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'is_published']);
        });

        /**
         * Every published version, kept. Terms and privacy pages are the ones a user
         * consented to on a particular date, so "what did it say in March" has to be
         * answerable — overwriting the row would destroy the only evidence.
         */
        Schema::create('cms_page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cms_page_id')->constrained('cms_pages')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title_en', 200);
            $table->string('title_hi', 200)->nullable();
            $table->longText('content_en');
            $table->longText('content_hi')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['cms_page_id', 'version']);
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('category', 60)->default('general');
            $table->string('question_en', 300);
            $table->string('question_hi', 300)->nullable();
            $table->text('answer_en');
            $table->text('answer_hi')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'is_active', 'sort_order']);
        });

        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title', 200);
            $table->text('body');
            $table->string('image_url', 500)->nullable();
            $table->string('deep_link', 500)->nullable();
            $table->string('audience', 20)->default('all');   // all segment user_list
            $table->json('audience_filter')->nullable();
            $table->json('channels')->nullable();             // push in_app
            // The size the audience was when it was sent, frozen. Re-counting later would
            // silently rewrite history as users sign up or churn.
            $table->unsignedInteger('audience_count')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->string('status', 20)->default('draft');   // draft scheduled sending sent cancelled failed
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('cms_page_versions');
        Schema::dropIfExists('cms_pages');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('banners');
    }
};
