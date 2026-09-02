<?php

namespace Tests\Feature\Admin;

use App\Domain\Analytics\DashboardService;
use App\Domain\Analytics\StatsRollup;
use App\Domain\Wallet\WalletService;
use App\Jobs\BuildReportExport;
use App\Models\AdminUser;
use App\Models\DailyStat;
use App\Models\ReportExport;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Epic A.2 acceptance criteria, including the NFR about raw-table scans. */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected AdminUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->superAdmin = AdminUser::create([
            'name'     => 'Super',
            'email'    => 'super@test.local',
            'password' => 'Password12345',
            'role_id'  => Role::where('key', Role::SUPER_ADMIN)->value('id'),
            'status'   => 'active',
        ]);

        // The KPI cache is shared across requests by design; tests must not inherit it.
        Cache::flush();
    }

    protected function makeUser(array $attributes = []): User
    {
        static $seq = 0;
        $seq++;

        // `created_at` is not fillable, so it is applied after the insert rather than
        // through create() — otherwise cohort tests silently run against today's date.
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $user = User::create(array_merge([
            'guftagu_id' => 'GF'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
            'phone'      => '+9198201'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'status'     => User::STATUS_ACTIVE,
            'agora_uid'  => 700000 + $seq,
        ], $attributes));

        if ($createdAt !== null) {
            $user->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        UserProfile::create(['user_id' => $user->id, 'display_name' => "Person {$seq}"]);

        return $user->refresh();
    }

    // -------------------------------------------------------------------- A.2a

    #[Test]
    public function kpis_report_the_live_counters(): void
    {
        $this->makeUser(['last_active_at' => now()]);
        $this->makeUser(['last_active_at' => now()->subDays(2)]);
        $this->makeUser(['status' => User::STATUS_BANNED]);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson($this->base.'/dashboard/kpis')
            ->assertOk();

        $this->assertSame(3, $response->json('data.users.total'));
        $this->assertSame(1, $response->json('data.users.banned'));
        $this->assertSame(1, $response->json('data.engagement.active_today'));

        // Rooms are honestly absent rather than silently zero.
        $this->assertFalse($response->json('data.rooms.available'));
    }

    #[Test]
    public function the_dau_mau_ratio_does_not_divide_by_zero_on_a_fresh_install(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson($this->base.'/dashboard/kpis')->assertOk();

        $this->assertSame(0, $response->json('data.users.total'));
        $this->assertSame(0.0, (float) $response->json('data.engagement.dau_mau_ratio'));
    }

    // --------------------------------------------------------------------- NFR

    #[Test]
    public function no_dashboard_query_scans_a_raw_transaction_table(): void
    {
        // Give the ledgers something to be tempted by.
        $user = $this->makeUser();
        app(WalletService::class)->adjust($user, 'coin', 'credit', 5000, 'seed', $this->superAdmin);
        app(StatsRollup::class)->run(now()->subDays(3), now());

        Cache::flush();
        DB::enableQueryLog();
        DB::flushQueryLog();

        foreach (['kpis', 'revenue', 'engagement'] as $endpoint) {
            $this->actingAs($this->superAdmin, 'sanctum-admin')
                ->getJson($this->base.'/dashboard/'.$endpoint)
                ->assertOk();
        }

        $offenders = [];

        foreach (DB::getQueryLog() as $entry) {
            foreach (DashboardService::ledgerTables() as $table) {
                if (str_contains($entry['query'], $table)) {
                    $offenders[] = $entry['query'];
                }
            }
        }

        DB::disableQueryLog();

        $this->assertSame([], $offenders, sprintf(
            "The dashboard must read from the rollup, not the ledgers. Offending queries:\n%s",
            implode("\n", $offenders),
        ));
    }

    // -------------------------------------------------------------------- A.2b

    #[Test]
    public function revenue_streams_are_separate_and_sum_to_the_range_total(): void
    {
        DailyStat::create([
            'date' => now()->subDay()->toDateString(),
            'recharge_coins' => 1000, 'gifting_coins' => 400, 'vip_coins' => 250, 'other_coins' => 50,
        ]);
        DailyStat::create([
            'date' => now()->toDateString(),
            'recharge_coins' => 500, 'gifting_coins' => 100, 'vip_coins' => 0, 'other_coins' => 0,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson($this->base.'/dashboard/revenue')
            ->assertOk();

        $totals = $response->json('data.totals');

        $this->assertSame(1500, $totals['recharge']);
        $this->assertSame(500, $totals['gifting']);
        $this->assertSame(250, $totals['vip']);

        // A.2b: the streams must add up to the range total exactly.
        $this->assertSame(
            $totals['recharge'] + $totals['gifting'] + $totals['vip'] + $totals['other'],
            $response->json('data.coin_total'),
        );
    }

    #[Test]
    public function the_rollup_classifies_every_ledger_type_so_nothing_is_lost(): void
    {
        $user = $this->makeUser();
        $wallet = app(WalletService::class)->forUser($user);

        // A type the rollup has no named stream for must still be counted somewhere.
        DB::table('coin_transactions')->insert([
            'uuid' => (string) str()->uuid(), 'wallet_id' => $wallet->id, 'user_id' => $user->id,
            'direction' => 'credit', 'amount' => 321, 'balance_before' => 0, 'balance_after' => 321,
            'type' => 'event_reward', 'created_at' => now(),
        ]);

        app(StatsRollup::class)->rollupDay(now());

        $stat = DailyStat::where('date', now()->toDateString())->first();

        $this->assertSame(321, $stat->other_coins, 'An unclassified ledger type must land in other_coins.');
    }

    #[Test]
    public function the_rollup_is_idempotent(): void
    {
        $this->makeUser(['created_at' => now()]);

        app(StatsRollup::class)->rollupDay(now());
        $first = DailyStat::where('date', now()->toDateString())->first()->new_users;

        app(StatsRollup::class)->rollupDay(now());
        $second = DailyStat::where('date', now()->toDateString())->first()->new_users;

        $this->assertSame($first, $second, 'Re-running a day must overwrite, not double-count.');
        $this->assertSame(1, DailyStat::where('date', now()->toDateString())->count());
    }

    #[Test]
    public function weekly_granularity_sums_flows_but_not_running_totals(): void
    {
        // Anchored to the start of the ISO week so all three days land in ONE bucket —
        // counting back from "today" straddles a week boundary and splits them.
        $weekStart = now()->startOfWeek();

        foreach ([0, 1, 2] as $offset) {
            DailyStat::create([
                'date'           => $weekStart->copy()->addDays($offset)->toDateString(),
                'new_users'      => 5,
                'total_users'    => 100 + $offset,   // a running total, not a daily flow
                'recharge_coins' => 10,
            ]);
        }

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson($this->base.'/dashboard/engagement?granularity=week&from='
                .$weekStart->toDateString().'&to='.$weekStart->copy()->addDays(2)->toDateString())
            ->assertOk();

        $this->assertCount(1, $response->json('data.series'), 'All three days share one ISO week.');

        $bucket = $response->json('data.series.0');

        $this->assertSame(15, $bucket['new_users'], 'Daily flows should sum across the bucket.');
        $this->assertSame(102, $bucket['total_users'], 'A running total must take the last value, not the sum.');
    }

    // -------------------------------------------------------------------- A.2c

    #[Test]
    public function retention_is_reported_per_signup_cohort_and_labels_what_it_measures(): void
    {
        // Signed up 40 days ago, last seen 35 days later — retained past every horizon.
        $this->makeUser([
            'created_at'     => now()->subDays(40),
            'last_active_at' => now()->subDays(5),
        ]);

        // Signed up the same week but never came back.
        $this->makeUser([
            'created_at'     => now()->subDays(39),
            'last_active_at' => now()->subDays(39),
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson($this->base.'/dashboard/engagement')
            ->assertOk();

        $retention = $response->json('data.retention');

        // The name is not "D1/D7/D30" because that is not what is being measured.
        $this->assertSame('still_active_after', $retention['measure']);
        $this->assertNotEmpty($retention['note']);

        $cohort = collect($retention['cohorts'])->firstWhere('signed_up', 2);

        $this->assertNotNull($cohort, 'Both users should share one weekly cohort.');
        $this->assertSame(0.5, $cohort['d1']);
        $this->assertSame(0.5, $cohort['d30']);
    }

    // -------------------------------------------------------------------- A.2d

    #[Test]
    public function requesting_an_export_queues_a_job_and_does_not_block(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/dashboard/export', ['type' => 'revenue'])
            ->assertStatus(202);

        $this->assertSame('queued', $response->json('data.status'));

        Bus::assertDispatched(BuildReportExport::class);
    }

    #[Test]
    public function the_job_produces_a_downloadable_csv(): void
    {
        Storage::fake('local');

        // The export now goes through the A.10 ReportEngine, which reads the **ledger**
        // rather than the daily_stats rollup — one "revenue" CSV, not two that disagree.
        // So the fixture has to be a real ledger row.
        $user = $this->makeUser();
        $wallet = \App\Models\Wallet::firstOrCreate(['user_id' => $user->id]);

        \Illuminate\Support\Facades\DB::table('coin_transactions')->insert([
            'uuid'           => (string) \Illuminate\Support\Str::uuid(),
            'wallet_id'      => $wallet->id,
            'user_id'        => $user->id,
            'direction'      => 'credit',
            'amount'         => 1000,
            'balance_before' => 0,
            'balance_after'  => 1000,
            'type'           => 'recharge',
            'created_at'     => now(),
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/dashboard/export', ['type' => 'revenue'])
            ->assertStatus(202);

        $export = ReportExport::first();

        // Run the worker's work inline, then check what it wrote.
        (new BuildReportExport($export->id))->handle(app(\App\Domain\Reports\ReportEngine::class));

        $export->refresh();

        $this->assertSame(ReportExport::READY, $export->status);
        // One row per day across the requested range, quiet days included — a gap in a
        // revenue report reads as missing data rather than as a day with no sales.
        $this->assertGreaterThan(1, $export->row_count);
        Storage::disk('local')->assertExists($export->file_path);

        $csv = Storage::disk('local')->get($export->file_path);
        $this->assertStringContainsString('coins_purchased', $csv);
        $this->assertStringContainsString('1000', $csv);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->get($this->base."/dashboard/exports/{$export->id}/download")
            ->assertOk();
    }

    #[Test]
    public function an_export_cannot_be_downloaded_by_another_admin(): void
    {
        Storage::fake('local');

        $other = AdminUser::create([
            'name' => 'Other', 'email' => 'other@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::ADMIN)->value('id'), 'status' => 'active',
        ]);

        DailyStat::create(['date' => now()->toDateString(), 'recharge_coins' => 10]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/dashboard/export', ['type' => 'revenue'])->assertStatus(202);

        $export = ReportExport::first();
        (new BuildReportExport($export->id))->handle(app(\App\Domain\Reports\ReportEngine::class));

        // A month of financial data — holding the id is not authorisation.
        $this->actingAs($other, 'sanctum-admin')
            ->getJson($this->base."/dashboard/exports/{$export->id}/download")
            ->assertStatus(403);
    }

    #[Test]
    public function an_unfinished_export_reports_its_status_rather_than_a_broken_file(): void
    {
        Bus::fake();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/dashboard/export', ['type' => 'revenue'])->assertStatus(202);

        $export = ReportExport::first();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson($this->base."/dashboard/exports/{$export->id}/download")
            ->assertStatus(400)
            ->assertJsonPath('error.details.status', 'queued');
    }

    // ------------------------------------------------------------ route gating

    #[Test]
    public function a_moderator_cannot_see_the_dashboard_or_export_from_it(): void
    {
        $moderator = AdminUser::create([
            'name' => 'Mod', 'email' => 'mod@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::MODERATOR)->value('id'), 'status' => 'active',
        ]);

        $this->actingAs($moderator, 'sanctum-admin')
            ->getJson($this->base.'/dashboard/kpis')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson($this->base.'/dashboard/export', ['type' => 'revenue'])
            ->assertStatus(403);
    }

    #[Test]
    public function a_reversed_date_range_is_corrected_rather_than_returning_nothing(): void
    {
        DailyStat::create(['date' => now()->subDays(3)->toDateString(), 'new_users' => 7]);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson($this->base.'/dashboard/engagement?from='.now()->toDateString().'&to='.now()->subDays(7)->toDateString())
            ->assertOk();

        $this->assertNotEmpty($response->json('data.series'), 'A reversed range should be swapped, not empty.');
    }
}
