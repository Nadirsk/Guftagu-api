<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Announcement;
use App\Models\Banner;
use App\Models\CmsPage;
use App\Models\CmsPageVersion;
use App\Models\Faq;
use Illuminate\Database\Seeder;

/**
 * Content fixtures for epic A.10a.
 *
 *     php artisan db:seed --class=CmsSeeder
 *
 * The banners deliberately cover all four states — live, scheduled, expired and off — so
 * the derived-visibility rule is visible on the screen rather than only in a test.
 */
class CmsSeeder extends Seeder
{
    /** [title, placement, days from now to start, days from now to end, active] */
    public const BANNERS = [
        ['Diwali coin sale', 'home_top', -2, 12, true],
        ['New music rooms', 'room_list', -30, null, true],
        ['Weekend recharge bonus', 'wallet', 3, 10, true],     // scheduled
        ['Independence Day event', 'event', -60, -30, true],   // expired
        ['Old referral push', 'home_top', -90, null, false],   // switched off
    ];

    public const FAQS = [
        ['coins', 'How do I buy coins?', 'Open your wallet and pick a recharge pack. Payment is handled by our gateway.', true],
        ['coins', 'Do coins expire?', 'No. Coins stay in your wallet until you spend them.', true],
        ['diamonds', 'How do I withdraw my diamonds?', 'Complete KYC, then request a withdrawal from the wallet screen. Requests are reviewed within two working days.', true],
        ['rooms', 'Why was I removed from a room?', 'A host or moderator can remove anyone from their room. Repeated removals may lead to a warning.', false],
        ['safety', 'How do I report someone?', 'Long-press their profile and choose Report. Every report is reviewed by a human.', true],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->error('CmsSeeder refuses to run outside local/testing.');

            return;
        }

        $admin = AdminUser::query()->orderBy('id')->first();

        foreach (self::BANNERS as $index => [$title, $placement, $startsIn, $endsIn, $active]) {
            $banner = Banner::query()->firstOrCreate(
                ['title' => $title],
                [
                    'image_url'   => "https://cdn.example.com/banners/{$placement}-{$index}.jpg",
                    'placement'   => $placement,
                    'action_type' => 'url',
                    'action_value' => 'https://guftagu.example.com/offers',
                    'sort_order'  => ($index + 1) * 10,
                    'starts_at'   => now()->addDays($startsIn),
                    'ends_at'     => $endsIn === null ? null : now()->addDays($endsIn),
                    'is_active'   => $active,
                    'created_by'  => $admin?->id,
                    // Approved, because these represent content already signed off. An
                    // unapproved banner never shows (B.3b), so leaving this null would
                    // make every seeded banner read `awaiting_approval`.
                    'approved_by' => $admin?->id,
                ],
            );

            // The counters are not fillable on purpose, so they are forced on here rather
            // than passed to create() where they would be silently dropped.
            if ($banner->wasRecentlyCreated) {
                $impressions = random_int(500, 20000);

                $banner->forceFill([
                    'impression_count' => $impressions,
                    'click_count'      => (int) round($impressions * (random_int(5, 60) / 1000)),
                ])->save();
            }
        }

        // One left unapproved deliberately, so the Admin approval queue is not empty on a
        // fresh install and the gate is visible rather than theoretical.
        Banner::query()->firstOrCreate(
            ['title' => 'Manager draft — festival teaser'],
            [
                'image_url'   => 'https://cdn.example.com/banners/teaser.jpg',
                'placement'   => 'home_top',
                'action_type' => 'none',
                'sort_order'  => 90,
                'is_active'   => true,
                'created_by'  => $admin?->id,
                'approved_by' => null,
            ],
        );

        $this->command->info(count(self::BANNERS).' banners in place, covering every state, plus one awaiting approval.');

        Announcement::query()->firstOrCreate(
            ['title_en' => 'Scheduled maintenance'],
            [
                'title_hi' => 'निर्धारित रखरखाव',
                'body_en'  => 'The app will be briefly unavailable on Sunday between 3am and 4am IST.',
                'body_hi'  => 'रविवार को सुबह 3 से 4 बजे तक ऐप कुछ देर के लिए उपलब्ध नहीं रहेगा।',
                'type'     => 'marquee',
                'starts_at' => now()->subDay(),
                'ends_at'  => now()->addDays(5),
                'is_active' => true,
                'created_by' => $admin?->id,
            ],
        );

        // Deliberately English-only, so the missing-Hindi warning has something to report.
        Announcement::query()->firstOrCreate(
            ['title_en' => 'New gift collection'],
            [
                'body_en'   => 'Twelve new gifts have landed in the store.',
                'type'      => 'popup',
                'starts_at' => now(),
                'is_active' => true,
                'created_by' => $admin?->id,
            ],
        );

        $this->seedPages($admin);

        foreach (self::FAQS as $index => [$category, $question, $answer, $active]) {
            Faq::query()->firstOrCreate(
                ['question_en' => $question],
                [
                    'category'   => $category,
                    'answer_en'  => $answer,
                    'sort_order' => ($index + 1) * 10,
                    'is_active'  => $active,
                ],
            );
        }

        $this->command->info(count(self::FAQS).' FAQs seeded (some without Hindi, on purpose).');
    }

    protected function seedPages(?AdminUser $admin): void
    {
        $pages = [
            ['terms', 'terms', 'Terms of Service', 'By using Guftagu you agree to behave decently in voice rooms.'],
            ['privacy', 'privacy', 'Privacy Policy', 'We collect your phone number to sign you in, and nothing we do not need.'],
            ['community-guidelines', 'guidelines', 'Community Guidelines', 'No harassment, no fraud, no sharing contact details for off-platform payment.'],
        ];

        foreach ($pages as [$slug, $type, $title, $content]) {
            $page = CmsPage::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'title_en'   => $title,
                    'content_en' => $content,
                    'type'       => $type,
                    'version'    => 0,
                    'is_published' => false,
                    'updated_by' => $admin?->id,
                ],
            );

            if ($page->versions()->exists()) {
                continue;
            }

            // Two published versions, so the version history and the restore flow have
            // something real to work against.
            foreach ([1, 2] as $version) {
                CmsPageVersion::create([
                    'cms_page_id' => $page->id,
                    'version'     => $version,
                    'title_en'    => $title,
                    'content_en'  => $version === 1 ? $content : $content.' Updated for clarity.',
                    'created_by'  => $admin?->id,
                    'created_at'  => now()->subDays(30 - ($version * 10)),
                ]);
            }

            $page->forceFill([
                'version'      => 2,
                'content_en'   => $content.' Updated for clarity.',
                'is_published' => true,
                'published_at' => now()->subDays(10),
            ])->save();
        }

        $this->command->info(count($pages).' CMS pages published, each with a version history.');
    }
}
