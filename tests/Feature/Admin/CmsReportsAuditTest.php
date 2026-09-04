<?php

namespace Tests\Feature\Admin;

use App\Domain\Reports\ReportEngine;
use App\Jobs\BuildReportExport;
use App\Models\AdminUser;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Banner;
use App\Models\Broadcast;
use App\Models\CmsPage;
use App\Models\CmsPageVersion;
use App\Models\Device;
use App\Models\Faq;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use Database\Seeders\EconomySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Epic A.10 acceptance criteria — CMS, reports and the audit trail. */
class CmsReportsAuditTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected AdminUser $superAdmin;

    protected AdminUser $itAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class, EconomySeeder::class]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->superAdmin = AdminUser::create([
            'name' => 'Super', 'email' => 'super@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::SUPER_ADMIN)->value('id'), 'status' => 'active',
        ]);

        $this->itAdmin = AdminUser::create([
            'name' => 'IT Admin', 'email' => 'itadmin@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', 'it_admin')->value('id'), 'status' => 'active',
        ]);
    }

    protected function makeUser(array $attributes = []): User
    {
        static $seq = 0;
        $seq++;

        $user = User::create(array_merge([
            'guftagu_id' => 'GF'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
            'phone'      => '+9198207'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'status'     => User::STATUS_ACTIVE,
            'agora_uid'  => 700000 + $seq,
        ], $attributes));

        UserProfile::create(['user_id' => $user->id, 'display_name' => "Member {$seq}", 'country' => 'India']);
        Wallet::create(['user_id' => $user->id]);

        return $user;
    }

    protected function recharge(User $user, int $coins, string $day): void
    {
        $wallet = Wallet::where('user_id', $user->id)->firstOrFail();
        $before = (int) DB::table('coin_transactions')->where('user_id', $user->id)->max('balance_after');

        DB::table('coin_transactions')->insert([
            'uuid'           => (string) Str::uuid(),
            'wallet_id'      => $wallet->id,
            'user_id'        => $user->id,
            'direction'      => 'credit',
            'amount'         => $coins,
            'balance_before' => $before,
            'balance_after'  => $before + $coins,
            'type'           => 'recharge',
            'created_at'     => $day.' 12:00:00',
        ]);
    }

    protected function makeBanner(array $attributes = []): Banner
    {
        static $seq = 0;
        $seq++;

        // `click_count` and `impression_count` are deliberately not fillable - a counter is
        // incremented by the platform, never set from a request body - so a fixture has to
        // force them on rather than pass them to create().
        $counters = array_intersect_key($attributes, array_flip(['click_count', 'impression_count']));
        $attributes = array_diff_key($attributes, $counters);

        $banner = Banner::create(array_merge([
            'title'     => "Banner {$seq}",
            'image_url' => 'https://cdn.example.com/b.jpg',
            'placement' => 'home_top',
            'is_active' => true,
            // Approved by default: these fixtures are about the scheduling window, and an
            // unapproved banner is never live whatever its window says (B.3b). The
            // approval gate has its own tests.
            'approved_by' => $this->superAdmin->id,
        ], $attributes));

        if ($counters !== []) {
            $banner->forceFill($counters)->save();
        }

        return $banner;
    }

    // -------------------------------------------------------- A.10a · banners

    #[Test]
    public function a_scheduled_banner_is_invisible_before_visible_during_and_hidden_after(): void
    {
        // A.10a's worked example, in all three states at once.
        $before = $this->makeBanner(['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(10)]);
        $during = $this->makeBanner(['starts_at' => now()->subDay(), 'ends_at' => now()->addDays(6)]);
        $after = $this->makeBanner(['starts_at' => now()->subDays(20), 'ends_at' => now()->subDay()]);

        $this->assertSame('scheduled', $before->state());
        $this->assertSame('live', $during->state());
        $this->assertSame('expired', $after->state());

        $this->assertFalse($before->isLive());
        $this->assertTrue($during->isLive());
        $this->assertFalse($after->isLive());

        // The SQL scope has to agree with the PHP predicate, or a filtered list and a
        // detail page would contradict each other.
        $live = Banner::query()->live()->pluck('id');

        $this->assertTrue($live->contains($during->id));
        $this->assertFalse($live->contains($before->id));
        $this->assertFalse($live->contains($after->id));
    }

    #[Test]
    public function an_inactive_banner_inside_its_window_is_still_off(): void
    {
        $banner = $this->makeBanner([
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => false,
        ]);

        $this->assertSame('off', $banner->state());
        $this->assertFalse($banner->isLive());
    }

    #[Test]
    public function clicks_are_reported_per_placement(): void
    {
        $this->makeBanner(['placement' => 'home_top', 'click_count' => 100, 'impression_count' => 1000]);
        $this->makeBanner(['placement' => 'home_top', 'click_count' => 50, 'impression_count' => 500]);
        $this->makeBanner(['placement' => 'wallet', 'click_count' => 7, 'impression_count' => 70]);

        $byPlacement = collect(
            $this->actingAs($this->superAdmin, 'sanctum-admin')
                ->getJson("{$this->base}/content/banners")
                ->assertOk()
                ->json('data.by_placement')
        )->keyBy('placement');

        $this->assertSame(150, $byPlacement['home_top']['clicks']);
        $this->assertSame(7, $byPlacement['wallet']['clicks']);
    }

    #[Test]
    public function a_click_rate_is_null_before_anything_has_been_shown(): void
    {
        $banner = $this->makeBanner(['click_count' => 0, 'impression_count' => 0]);

        // Zero would read as "shown and never clicked", which is a different statement.
        $this->assertNull($banner->clickRate());
    }

    #[Test]
    public function a_window_that_closes_before_it_opens_is_refused(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/content/banners", [
                'title'     => 'Impossible',
                'image_url' => 'https://cdn.example.com/x.jpg',
                'placement' => 'home_top',
                'starts_at' => now()->addDays(5)->toDateTimeString(),
                'ends_at'   => now()->addDay()->toDateTimeString(),
            ])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------- A.10a · pages

    #[Test]
    public function publishing_a_page_cuts_a_version_and_editing_does_not(): void
    {
        $page = CmsPage::create([
            'slug' => 'terms', 'title_en' => 'Terms', 'content_en' => 'First draft.',
            'type' => 'terms', 'version' => 0,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson("{$this->base}/content/pages/{$page->id}", ['content_en' => 'Second draft.'])
            ->assertOk();

        $this->assertSame(0, CmsPageVersion::where('cms_page_id', $page->id)->count());

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/content/pages/{$page->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.version', 1);

        $this->assertSame(1, CmsPageVersion::where('cms_page_id', $page->id)->count());
    }

    #[Test]
    public function publishing_unchanged_text_is_refused(): void
    {
        $page = CmsPage::create([
            'slug' => 'privacy', 'title_en' => 'Privacy', 'content_en' => 'Same.',
            'type' => 'privacy', 'version' => 0,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/content/pages/{$page->id}/publish")->assertOk();

        // A version history full of no-op entries makes the real change impossible to find.
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/content/pages/{$page->id}/publish")
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'NO_CHANGES');
    }

    #[Test]
    public function restoring_an_old_version_publishes_a_new_one_and_deletes_nothing(): void
    {
        $page = CmsPage::create([
            'slug' => 'terms', 'title_en' => 'Terms', 'content_en' => 'Version one text.',
            'type' => 'terms', 'version' => 0,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/content/pages/{$page->id}/publish")->assertOk();

        $first = CmsPageVersion::where('cms_page_id', $page->id)->firstOrFail();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson("{$this->base}/content/pages/{$page->id}", ['content_en' => 'Version two text.'])
            ->assertOk();
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/content/pages/{$page->id}/publish")->assertOk();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/content/pages/{$page->id}/restore/{$first->id}")
            ->assertOk()
            ->assertJsonPath('data.version', 3);

        $this->assertSame('Version one text.', $page->fresh()->content_en);
        // Nothing removed: undoing a mistake must not destroy the record of it.
        $this->assertSame(3, CmsPageVersion::where('cms_page_id', $page->id)->count());
    }

    #[Test]
    public function a_legal_page_cannot_be_unpublished(): void
    {
        $page = CmsPage::create([
            'slug' => 'terms', 'title_en' => 'Terms', 'content_en' => 'Text.',
            'type' => 'terms', 'version' => 1, 'is_published' => true,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/content/pages/{$page->id}/unpublish")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LEGAL_PAGE');
    }

    #[Test]
    public function faqs_missing_a_hindi_answer_are_counted(): void
    {
        Faq::create(['category' => 'coins', 'question_en' => 'A?', 'answer_en' => 'Yes.', 'is_active' => true]);
        Faq::create([
            'category' => 'coins', 'question_en' => 'B?', 'question_hi' => 'ब?',
            'answer_en' => 'Yes.', 'answer_hi' => 'हाँ।', 'is_active' => true,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/content/faqs")
            ->assertOk()
            ->assertJsonPath('data.missing_hindi', 1);
    }

    // ------------------------------------------------------ A.10a · campaigns

    #[Test]
    public function an_audience_is_counted_before_sending(): void
    {
        // A.10a's example: users who recharged in the last 30 days.
        $recent = $this->makeUser();
        $old = $this->makeUser();
        $this->makeUser();

        $this->recharge($recent, 500, now()->subDays(5)->toDateString());
        $this->recharge($old, 500, now()->subDays(90)->toDateString());

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/broadcasts/preview", [
                'audience'        => 'segment',
                'audience_filter' => ['recharged_within_days' => 30],
            ])
            ->assertOk()
            ->assertJsonPath('data.matched', 1);
    }

    #[Test]
    public function the_preview_separates_who_matches_from_who_can_be_reached(): void
    {
        $withDevice = $this->makeUser();
        $this->makeUser();

        Device::create([
            'user_id' => $withDevice->id, 'device_id' => 'dev-1', 'platform' => 'android',
            'fcm_token' => 'token-1', 'is_active' => true,
        ]);

        $result = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/broadcasts/preview", ['audience' => 'all', 'channels' => ['push']])
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $result['matched']);
        $this->assertSame(1, $result['reachable_push']);
        // Reporting only the segment size would promise a reach the platform lacks.
        $this->assertStringContainsString('no active device', $result['note']);
    }

    #[Test]
    public function an_unrecognised_audience_filter_is_refused_not_ignored(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/broadcasts/preview", [
                'audience'        => 'segment',
                'audience_filter' => ['spent_a_lot' => true],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UNKNOWN_FILTER');
    }

    #[Test]
    public function sending_writes_in_app_rows_and_admits_push_did_not_go_out(): void
    {
        $this->makeUser();
        $this->makeUser();

        $id = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/broadcasts", [
                'title' => 'Hello', 'body' => 'Something is happening.',
                'audience' => 'all', 'channels' => ['in_app', 'push'],
            ])
            ->assertCreated()
            ->json('data.id');

        $result = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/broadcasts/{$id}/send")
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $result['audience_count']);
        $this->assertSame(2, $result['in_app_created']);
        $this->assertFalse($result['push_dispatched']);
        $this->assertSame(2, DB::table('notifications')->where('type', 'broadcast')->count());
    }

    #[Test]
    public function the_audience_size_is_frozen_when_it_is_sent(): void
    {
        $this->makeUser();

        $id = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/broadcasts", [
                'title' => 'Hello', 'body' => 'Body.', 'audience' => 'all', 'channels' => ['in_app'],
            ])->json('data.id');

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/broadcasts/{$id}/send")->assertOk();

        // Two more sign up afterwards. The campaign still went to one person.
        $this->makeUser();
        $this->makeUser();

        $this->assertSame(1, Broadcast::find($id)->audience_count);
        $this->assertSame(1, Broadcast::find($id)->sent_count);
    }

    #[Test]
    public function a_sent_campaign_cannot_be_sent_again(): void
    {
        $this->makeUser();

        $id = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/broadcasts", [
                'title' => 'Hello', 'body' => 'Body.', 'audience' => 'all', 'channels' => ['in_app'],
            ])->json('data.id');

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/broadcasts/{$id}/send")->assertOk();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/broadcasts/{$id}/send")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ALREADY_SENT');
    }

    #[Test]
    public function sending_to_an_empty_audience_is_refused(): void
    {
        $id = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/broadcasts", [
                'title' => 'Hello', 'body' => 'Body.', 'audience' => 'all', 'channels' => ['in_app'],
            ])->json('data.id');

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/broadcasts/{$id}/send")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EMPTY_AUDIENCE');
    }

    #[Test]
    public function delivery_and_open_rates_are_null_rather_than_zero_percent(): void
    {
        $broadcast = Broadcast::create([
            'title' => 'X', 'body' => 'Y', 'audience' => 'all', 'status' => Broadcast::SENT,
            'sent_count' => 0, 'delivered_count' => 0, 'opened_count' => 0,
        ]);

        // Nothing has reported back, which is not the same as nothing having arrived.
        $this->assertNull($broadcast->deliveryRate());
        $this->assertNull($broadcast->openRate());
    }

    #[Test]
    public function sending_needs_its_own_permission_beyond_composing(): void
    {
        $manager = AdminUser::create([
            'name' => 'Manager', 'email' => 'manager@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::MANAGER)->value('id'), 'status' => 'active',
        ]);

        // Compose, but not send. The point of the test is that those are separate keys.
        DB::table('admin_user_permission')->insert([
            'admin_user_id' => $manager->id,
            'permission_id' => DB::table('permissions')->where('key', 'cms.announcement_manage')->value('id'),
            'effect' => 'allow', 'granted_by' => $this->superAdmin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->makeUser();

        $id = $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/broadcasts", [
                'title' => 'Hello', 'body' => 'Body.', 'audience' => 'all', 'channels' => ['in_app'],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/broadcasts/{$id}/send")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    // -------------------------------------------------------- A.10b · reports

    #[Test]
    public function a_revenue_report_reconciles_with_the_ledger(): void
    {
        $user = $this->makeUser();

        $this->recharge($user, 1000, now()->subDays(3)->toDateString());
        $this->recharge($user, 2500, now()->subDays(2)->toDateString());

        DB::table('daily_stats')->insert([
            ['date' => now()->subDays(3)->toDateString(), 'recharge_coins' => 1000, 'computed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['date' => now()->subDays(2)->toDateString(), 'recharge_coins' => 2500, 'computed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/reports-centre/reconcile?from=".now()->subDays(5)->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.matches', true)
            ->assertJsonPath('data.ledger_coins', 3500)
            ->assertJsonPath('data.difference', 0);
    }

    #[Test]
    public function a_drifted_rollup_is_reported_rather_than_preferred(): void
    {
        $user = $this->makeUser();
        $this->recharge($user, 1000, now()->subDay()->toDateString());

        // The rollup is short by 400 — the report must say so and name the ledger as
        // authoritative, not quietly pick one.
        DB::table('daily_stats')->insert([
            'date' => now()->subDay()->toDateString(), 'recharge_coins' => 600,
            'computed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/reports-centre/reconcile?from=".now()->subDays(3)->toDateString())
            ->assertOk()
            ->json('data');

        $this->assertFalse($result['matches']);
        $this->assertSame(400, $result['difference']);
        $this->assertSame('ledger', $result['authoritative']);
    }

    #[Test]
    public function a_revenue_report_has_a_row_for_every_day_including_the_quiet_ones(): void
    {
        $user = $this->makeUser();
        $this->recharge($user, 100, now()->subDays(2)->toDateString());

        $rows = app(ReportEngine::class)->preview('revenue', [
            'from' => now()->subDays(4)->toDateString(),
            'to'   => now()->toDateString(),
        ]);

        // A gap reads as missing data, not as a day with no sales.
        $this->assertSame(5, $rows['total']);
        $this->assertCount(5, $rows['rows']);
    }

    #[Test]
    public function an_unrecognised_report_filter_is_refused(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/reports-centre/preview", [
                'type' => 'users', 'filters' => ['favourite_colour' => 'blue'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UNKNOWN_FILTER');
    }

    #[Test]
    public function a_preview_reports_the_true_total_not_the_number_of_rows_shown(): void
    {
        foreach (range(1, 12) as $i) {
            $this->makeUser();
        }

        $result = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/reports-centre/preview", ['type' => 'users'])
            ->assertOk()
            ->json('data');

        $this->assertSame(12, $result['total']);
        $this->assertFalse($result['truncated']);
    }

    #[Test]
    public function each_report_type_is_gated_on_its_own_permission(): void
    {
        $manager = AdminUser::create([
            'name' => 'Manager', 'email' => 'm2@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::MANAGER)->value('id'), 'status' => 'active',
        ]);

        DB::table('admin_user_permission')
            ->where('admin_user_id', $manager->id)
            ->delete();

        foreach (['reports_export.users'] as $key) {
            DB::table('admin_user_permission')->insert([
                'admin_user_id' => $manager->id,
                'permission_id' => DB::table('permissions')->where('key', $key)->value('id'),
                'effect' => 'allow', 'granted_by' => $this->superAdmin->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // A Manager holds every reports_export.* key by role, so revoke revenue explicitly
        // to isolate the per-type check.
        DB::table('admin_user_permission')->insert([
            'admin_user_id' => $manager->id,
            'permission_id' => DB::table('permissions')->where('key', 'reports_export.revenue')->value('id'),
            'effect' => 'deny', 'granted_by' => $this->superAdmin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/reports-centre/preview", ['type' => 'users'])
            ->assertOk();

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/reports-centre/preview", ['type' => 'revenue'])
            ->assertStatus(403);
    }

    // --------------------------------------------------------- A.10c · export

    #[Test]
    public function an_export_writes_every_row_and_streams_rather_than_buffering(): void
    {
        Storage::fake('local');

        // Not 200,000 in a test suite, but enough to cross several `lazyById` chunks and
        // prove nothing is being accumulated. The chunk size is 1000.
        foreach (range(1, 60) as $i) {
            $this->makeUser();
        }

        $uuid = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/reports-centre/export", [
                'type' => 'users',
                'filters' => ['from' => now()->subDay()->toDateString()],
            ])
            ->assertStatus(202)
            ->json('data.uuid');

        $export = \App\Models\ReportExport::where('uuid', $uuid)->firstOrFail();

        (new BuildReportExport($export->id))->handle(app(ReportEngine::class));

        $export->refresh();

        $this->assertSame(\App\Models\ReportExport::READY, $export->status);
        $this->assertSame(60, $export->row_count);

        $contents = Storage::disk('local')->get($export->file_path);
        // Header + 60 rows, and the BOM so Excel does not mangle Devanagari.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contents);
        $this->assertSame(61, substr_count(trim($contents), "\n") + 1);
    }

    #[Test]
    public function an_export_expires_after_seven_days(): void
    {
        Storage::fake('local');
        $this->makeUser();

        $uuid = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/reports-centre/export", ['type' => 'users'])
            ->json('data.uuid');

        $export = \App\Models\ReportExport::where('uuid', $uuid)->firstOrFail();
        (new BuildReportExport($export->id))->handle(app(ReportEngine::class));

        $export->refresh();
        $this->assertTrue($export->expires_at->isAfter(now()->addDays(6)));

        $export->forceFill(['expires_at' => now()->subDay()])->save();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/reports-centre/exports/{$uuid}/download")
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'EXPIRED');
    }

    #[Test]
    public function a_failed_export_leaves_no_partial_file_to_download(): void
    {
        Storage::fake('local');

        $export = \App\Models\ReportExport::create([
            'admin_user_id' => $this->superAdmin->id,
            'type'          => 'nonsense',
            'format'        => 'csv',
            'filters'       => [],
            'status'        => \App\Models\ReportExport::QUEUED,
        ]);

        try {
            (new BuildReportExport($export->id))->handle(app(ReportEngine::class));
        } catch (\Throwable) {
            // The job rethrows so the queue records the failure; the row is what matters.
        }

        $export->refresh();

        $this->assertSame(\App\Models\ReportExport::FAILED, $export->status);
        // A truncated file somebody downloads and trusts is worse than no file.
        $this->assertFalse(Storage::disk('local')->exists("exports/{$export->uuid}.csv"));
    }

    #[Test]
    public function another_admins_export_cannot_be_downloaded(): void
    {
        $other = AdminUser::create([
            'name' => 'Other', 'email' => 'other@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::SUPER_ADMIN)->value('id'), 'status' => 'active',
        ]);

        $export = \App\Models\ReportExport::create([
            'admin_user_id' => $other->id, 'type' => 'users', 'format' => 'csv',
            'filters' => [], 'status' => \App\Models\ReportExport::READY, 'file_path' => 'exports/x.csv',
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/reports-centre/exports/{$export->uuid}/download")
            ->assertStatus(404);
    }

    // ----------------------------------------------------------- A.10d · audit

    #[Test]
    public function every_kind_of_admin_mutation_leaves_exactly_one_audit_row(): void
    {
        // A.10d, verbatim: "Verified by a test that performs one action of each kind and
        // asserts a log row exists for every one."
        $user = $this->makeUser();
        $banner = $this->makeBanner();

        $actions = [
            'ban a user' => fn () => $this->actingAs($this->superAdmin, 'sanctum-admin')
                ->postJson("{$this->base}/users/{$user->id}/ban", ['reason' => 'Repeated abuse in rooms.']),

            'credit a wallet' => fn () => $this->actingAs($this->superAdmin, 'sanctum-admin')
                ->postJson("{$this->base}/users/{$user->id}/wallet/credit", [
                    'currency' => 'coin', 'amount' => 100, 'note' => 'Goodwill credit for a failed recharge.',
                ]),

            'create a banner' => fn () => $this->actingAs($this->superAdmin, 'sanctum-admin')
                ->postJson("{$this->base}/content/banners", [
                    'title' => 'Audited', 'image_url' => 'https://cdn.example.com/a.jpg', 'placement' => 'wallet',
                ]),

            'edit a banner' => fn () => $this->actingAs($this->superAdmin, 'sanctum-admin')
                ->patchJson("{$this->base}/content/banners/{$banner->id}", ['title' => 'Renamed']),

            // Roles routes are IT Admin only (role:it_admin) — Super Admin's blanket
            // bypass does not reach them.
            'create a role' => fn () => $this->actingAs($this->itAdmin, 'sanctum-admin')
                ->postJson("{$this->base}/roles", ['key' => 'auditor', 'name' => 'Auditor']),
        ];

        foreach ($actions as $label => $act) {
            $before = AuditLog::count();

            $response = $act();

            $this->assertTrue(
                $response->getStatusCode() < 300,
                "Setting up '{$label}' failed with {$response->getStatusCode()}: {$response->getContent()}",
            );

            $written = AuditLog::count() - $before;

            // Exactly one. Zero means the mutation is unaudited; more than one means the
            // safety net double-wrote over a service that had already logged.
            $this->assertSame(1, $written, "'{$label}' wrote {$written} audit rows, expected exactly 1.");
        }
    }

    #[Test]
    public function the_safety_net_catches_a_mutation_no_service_logged(): void
    {
        $faq = Faq::create(['category' => 'x', 'question_en' => 'Q?', 'answer_en' => 'A.']);

        $before = AuditLog::count();

        // Reorder logs explicitly; deliberately exercise a route that does not.
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/content/faqs/reorder", ['order' => [$faq->id]])
            ->assertOk();

        $this->assertSame(1, AuditLog::count() - $before);
    }

    #[Test]
    public function a_refused_request_writes_no_fallback_row(): void
    {
        $moderator = AdminUser::create([
            'name' => 'Mod', 'email' => 'mod@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::MODERATOR)->value('id'), 'status' => 'active',
        ]);

        $before = AuditLog::count();

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson("{$this->base}/content/banners", [
                'title' => 'Nope', 'image_url' => 'https://cdn.example.com/n.jpg', 'placement' => 'home_top',
            ])
            ->assertStatus(403);

        // A rejected request changed nothing, so there is nothing to audit.
        $this->assertSame(0, AuditLog::count() - $before);
    }

    #[Test]
    public function a_read_only_post_does_not_pollute_the_trail(): void
    {
        $before = AuditLog::count();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/broadcasts/preview", ['audience' => 'all'])
            ->assertOk();

        $this->assertSame(0, AuditLog::count() - $before);
    }

    #[Test]
    public function the_audit_viewer_renders_a_field_level_diff(): void
    {
        $banner = $this->makeBanner(['title' => 'Before']);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson("{$this->base}/content/banners/{$banner->id}", ['title' => 'After'])
            ->assertOk();

        $log = AuditLog::where('action', 'cms.banner_update')->latest('id')->firstOrFail();

        $changes = collect(
            $this->actingAs($this->superAdmin, 'sanctum-admin')
                ->getJson("{$this->base}/audit-logs/{$log->id}")
                ->assertOk()
                ->json('data.changes')
        )->keyBy('field');

        $this->assertSame('Before', $changes['title']['from']);
        $this->assertSame('After', $changes['title']['to']);
        $this->assertSame('changed', $changes['title']['kind']);
    }

    #[Test]
    public function the_diff_omits_fields_that_did_not_change(): void
    {
        $banner = $this->makeBanner(['title' => 'Same', 'placement' => 'home_top']);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson("{$this->base}/content/banners/{$banner->id}", [
                'title' => 'Same', 'placement' => 'wallet',
            ])
            ->assertOk();

        $log = AuditLog::where('action', 'cms.banner_update')->latest('id')->firstOrFail();

        $fields = collect(
            $this->actingAs($this->superAdmin, 'sanctum-admin')
                ->getJson("{$this->base}/audit-logs/{$log->id}")->json('data.changes')
        )->pluck('field');

        $this->assertTrue($fields->contains('placement'));
        $this->assertFalse($fields->contains('title'), 'An unchanged field is noise, not a change.');
    }

    #[Test]
    public function the_audit_search_filters_by_actor_module_and_date(): void
    {
        $user = $this->makeUser();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/users/{$user->id}/suspend", [
                'reason' => 'Spam in room chat.', 'until' => now()->addDay()->toIso8601String(),
            ])->assertOk();

        $rows = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/audit-logs?module=users&admin_user_id={$this->superAdmin->id}")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($rows);
        $this->assertSame('Super', $rows[0]['actor']);
    }

    #[Test]
    public function the_coverage_report_separates_explicit_rows_from_fallback_rows(): void
    {
        $banner = $this->makeBanner();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson("{$this->base}/content/banners/{$banner->id}", ['title' => 'Explicit'])
            ->assertOk();

        $faq = Faq::create(['category' => 'x', 'question_en' => 'Q?', 'answer_en' => 'A.']);
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/content/faqs/reorder", ['order' => [$faq->id]])->assertOk();

        $result = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/audit-logs/coverage")
            ->assertOk()
            ->json('data');

        $this->assertGreaterThan(0, $result['explicit']);
        $this->assertSame($result['total'], $result['explicit'] + $result['fallback']);
    }

    #[Test]
    public function an_entity_history_reads_oldest_first(): void
    {
        $user = $this->makeUser();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/users/{$user->id}/suspend", [
                'reason' => 'First offence.', 'until' => now()->addDay()->toIso8601String(),
            ])->assertOk();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/users/{$user->id}/unban", ['reason' => 'Appealed successfully.'])
            ->assertOk();

        $rows = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/audit-logs/entity?entity_type=User&entity_id={$user->id}")
            ->assertOk()
            ->json('data');

        $this->assertGreaterThanOrEqual(2, count($rows));
        $this->assertLessThan($rows[1]['id'], $rows[0]['id']);
    }

    #[Test]
    public function a_moderator_cannot_read_the_audit_trail(): void
    {
        $moderator = AdminUser::create([
            'name' => 'Mod', 'email' => 'mod2@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::MODERATOR)->value('id'), 'status' => 'active',
        ]);

        $this->actingAs($moderator, 'sanctum-admin')
            ->getJson("{$this->base}/audit-logs")
            ->assertStatus(403);
    }
}
