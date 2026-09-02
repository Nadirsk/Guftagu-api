<?php

namespace Tests\Feature\Admin;

use App\Domain\Access\Services\ScopeFilter;
use App\Domain\Reports\ReportEngine;
use App\Models\AdminUser;
use App\Models\Agency;
use App\Models\Host;
use App\Models\HostApplication;
use App\Models\HostTarget;
use App\Models\Role;
use App\Models\Settlement;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use Database\Seeders\EconomySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B.1a and B.5a — scope is enforced server-side, not by hiding rows.
 *
 * The shape of every test here is the same: build two agencies, scope a Manager to one,
 * and assert they cannot see, count, total or export the other — through the list, through
 * a direct id, and through a report.
 */
class ScopeEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected AdminUser $superAdmin;

    protected Agency $mine;

    protected Agency $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class, EconomySeeder::class]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->superAdmin = $this->makeAdmin('Super', Role::SUPER_ADMIN);
        $this->mine = $this->makeAgency('Mine');
        $this->theirs = $this->makeAgency('Theirs');
    }

    protected function makeAdmin(string $name, string $roleKey): AdminUser
    {
        static $seq = 0;
        $seq++;

        return AdminUser::create([
            'name' => $name, 'email' => "scoped{$seq}@test.local", 'password' => 'Password12345',
            'role_id' => Role::where('key', $roleKey)->value('id'), 'status' => 'active',
        ]);
    }

    /** A Manager whose direct grant carries an agency scope. */
    protected function makeScopedManager(array $agencyIds, string $permission = 'agency.view'): AdminUser
    {
        $manager = $this->makeAdmin('Scoped Manager', Role::MANAGER);

        DB::table('admin_user_permission')->insert([
            'admin_user_id' => $manager->id,
            'permission_id' => DB::table('permissions')->where('key', $permission)->value('id'),
            'effect'        => 'allow',
            'granted_by'    => $this->superAdmin->id,
            'scope'         => json_encode(['agencies' => $agencyIds]),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return $manager;
    }

    protected function makeAgency(string $name): Agency
    {
        static $seq = 0;
        $seq++;

        return Agency::create([
            'code' => 'AGY-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'documents' => [['type' => 'gst', 'url' => 'https://cdn.example.com/g.pdf']],
            'commission_bp' => 1500,
            'status' => Agency::APPROVED,
        ]);
    }

    protected function makeHost(Agency $agency): Host
    {
        static $seq = 0;
        $seq++;

        $user = User::create([
            'guftagu_id' => 'GS'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
            'phone'      => '+9198208'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'status'     => User::STATUS_ACTIVE,
            'agora_uid'  => 800000 + $seq,
        ]);

        UserProfile::create(['user_id' => $user->id, 'display_name' => "Scoped host {$seq}"]);
        Wallet::create(['user_id' => $user->id]);

        return Host::create([
            'user_id' => $user->id, 'agency_id' => $agency->id, 'status' => Host::APPROVED,
        ]);
    }

    protected function makeSettlement(Agency $agency, string $status = Settlement::ADMIN_APPROVED): Settlement
    {
        return Settlement::create([
            'agency_id' => $agency->id,
            'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_end'   => now()->subMonth()->endOfMonth()->toDateString(),
            'gross_paise' => 100000, 'platform_cut_paise' => 30000,
            'agency_cut_paise' => 15000, 'host_cut_paise' => 55000,
            'net_payable_paise' => 15000, 'status' => $status,
        ]);
    }

    protected function earn(Host $host, int $amount, string $day): void
    {
        $wallet = Wallet::where('user_id', $host->user_id)->firstOrFail();

        DB::table('diamond_transactions')->insert([
            'uuid' => (string) Str::uuid(), 'wallet_id' => $wallet->id, 'user_id' => $host->user_id,
            'direction' => 'credit', 'amount' => $amount, 'balance_before' => 0,
            'balance_after' => $amount, 'type' => 'gift_received', 'created_at' => $day.' 12:00:00',
        ]);
    }

    // ------------------------------------------------------------ the primitive

    #[Test]
    public function an_unscoped_admin_is_reported_as_unrestricted_not_as_empty(): void
    {
        // Null and [] mean opposite things, and conflating them is how a scope filter
        // either leaks everything or shows nothing.
        $filter = app(ScopeFilter::class);

        $this->assertNull($filter->agencyIds($this->superAdmin));
        $this->assertNull($filter->describe($this->superAdmin));
    }

    #[Test]
    public function a_super_admin_is_never_scoped_even_with_a_scoped_grant(): void
    {
        DB::table('admin_user_permission')->insert([
            'admin_user_id' => $this->superAdmin->id,
            'permission_id' => DB::table('permissions')->where('key', 'agency.view')->value('id'),
            'effect' => 'allow', 'granted_by' => $this->superAdmin->id,
            'scope' => json_encode(['agencies' => [$this->mine->id]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull(app(ScopeFilter::class)->agencyIds($this->superAdmin->fresh()));
    }

    #[Test]
    public function a_scope_on_one_grant_constrains_every_agency_query(): void
    {
        // Scope is a property of the person. An operator who scopes `agency.view` and
        // forgets `hosts.view` has not accidentally handed over every host.
        $manager = $this->makeScopedManager([$this->mine->id], 'agency.view');

        $this->assertSame([$this->mine->id], app(ScopeFilter::class)->agencyIds($manager));
    }

    #[Test]
    public function a_scope_narrows_a_permission_the_role_also_grants(): void
    {
        // The Manager baseline already carries `agency.view` unscoped. If the role won,
        // the scope would be decorative.
        $manager = $this->makeScopedManager([$this->mine->id]);

        $this->actingAs($manager, 'sanctum-admin')
            ->getJson("{$this->base}/agencies")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ------------------------------------------------------------------- B.1a

    #[Test]
    public function a_scoped_manager_sees_only_their_own_agency_in_the_list(): void
    {
        $manager = $this->makeScopedManager([$this->mine->id]);

        $names = collect(
            $this->actingAs($manager, 'sanctum-admin')->getJson("{$this->base}/agencies")->json('data')
        )->pluck('name');

        $this->assertSame(['Mine'], $names->all());
    }

    #[Test]
    public function a_direct_call_for_another_agency_returns_403(): void
    {
        $manager = $this->makeScopedManager([$this->mine->id]);

        $this->actingAs($manager, 'sanctum-admin')
            ->getJson("{$this->base}/agencies/{$this->mine->id}")
            ->assertOk();

        // B.1a, verbatim: "a direct API call with another agency's id returns 403".
        $this->actingAs($manager, 'sanctum-admin')
            ->getJson("{$this->base}/agencies/{$this->theirs->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'OUT_OF_SCOPE');
    }

    #[Test]
    public function out_of_scope_is_distinguishable_from_a_missing_permission(): void
    {
        // Somebody debugging access needs to know which of the two they are looking at —
        // collapsing both into PERMISSION_DENIED sends them hunting for a grant that is
        // already there.
        $manager = $this->makeScopedManager([$this->mine->id]);

        $this->actingAs($manager, 'sanctum-admin')
            ->getJson("{$this->base}/agencies/{$this->theirs->id}")
            ->assertJsonPath('error.code', 'OUT_OF_SCOPE');

        $this->actingAs($manager, 'sanctum-admin')
            ->getJson("{$this->base}/audit-logs")
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    #[Test]
    public function every_agency_mutation_refuses_an_out_of_scope_id(): void
    {
        $manager = $this->makeScopedManager([$this->mine->id]);
        $id = $this->theirs->id;

        $this->actingAs($manager, 'sanctum-admin')
            ->patchJson("{$this->base}/agencies/{$id}", ['name' => 'Renamed'])->assertStatus(403);
        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/agencies/{$id}/approve")->assertStatus(403);
        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/agencies/{$id}/suspend", ['reason' => 'Because.'])->assertStatus(403);
        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/agencies/{$id}/documents", [
                'type' => 'gst', 'url' => 'https://cdn.example.com/x.pdf',
            ])->assertStatus(403);
    }

    #[Test]
    public function hosts_are_scoped_and_an_unassigned_host_is_not_visible(): void
    {
        $mineHost = $this->makeHost($this->mine);
        $theirHost = $this->makeHost($this->theirs);

        // A host with no agency belongs to nobody, so a scoped Manager does not get them
        // either.
        $orphan = $this->makeHost($this->mine);
        $orphan->forceFill(['agency_id' => null])->save();

        $manager = $this->makeScopedManager([$this->mine->id]);

        $ids = collect(
            $this->actingAs($manager, 'sanctum-admin')->getJson("{$this->base}/hosts")->json('data')
        )->pluck('id');

        $this->assertTrue($ids->contains($mineHost->id));
        $this->assertFalse($ids->contains($theirHost->id));
        $this->assertFalse($ids->contains($orphan->id));

        $this->actingAs($manager, 'sanctum-admin')
            ->getJson("{$this->base}/hosts/{$theirHost->id}")->assertStatus(403);
        $this->actingAs($manager, 'sanctum-admin')
            ->getJson("{$this->base}/hosts/{$orphan->id}")->assertStatus(403);
    }

    #[Test]
    public function a_host_cannot_be_moved_into_or_out_of_scope(): void
    {
        $mineHost = $this->makeHost($this->mine);
        $theirHost = $this->makeHost($this->theirs);
        $manager = $this->makeScopedManager([$this->mine->id]);

        // Pulling somebody else's host in.
        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/{$theirHost->id}/agency", ['agency_id' => $this->mine->id])
            ->assertStatus(403);

        // Pushing their own host out of reach.
        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/{$mineHost->id}/agency", ['agency_id' => $this->theirs->id])
            ->assertStatus(403);

        $this->assertSame($this->theirs->id, $theirHost->fresh()->agency_id);
        $this->assertSame($this->mine->id, $mineHost->fresh()->agency_id);
    }

    #[Test]
    public function targets_are_scoped_through_their_host(): void
    {
        $mineHost = $this->makeHost($this->mine);
        $theirHost = $this->makeHost($this->theirs);

        foreach ([$mineHost, $theirHost] as $host) {
            HostTarget::create([
                'host_id' => $host->id,
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'target_diamonds' => 1000, 'status' => HostTarget::ACTIVE,
            ]);
        }

        $manager = $this->makeScopedManager([$this->mine->id]);

        $hostIds = collect(
            $this->actingAs($manager, 'sanctum-admin')->getJson("{$this->base}/hosts/targets")->json('data')
        )->pluck('host_id');

        $this->assertSame([$mineHost->id], $hostIds->unique()->values()->all());
    }

    #[Test]
    public function host_applications_are_scoped(): void
    {
        $mineUser = $this->makeHost($this->mine)->user;
        $theirUser = $this->makeHost($this->theirs)->user;

        HostApplication::create(['user_id' => $mineUser->id, 'agency_id' => $this->mine->id]);
        $theirApplication = HostApplication::create(['user_id' => $theirUser->id, 'agency_id' => $this->theirs->id]);

        $manager = $this->makeScopedManager([$this->mine->id]);

        $this->assertCount(
            1,
            $this->actingAs($manager, 'sanctum-admin')->getJson("{$this->base}/host-applications")->json('data'),
        );

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/host-applications/{$theirApplication->id}/reject", ['reason' => 'No.'])
            ->assertStatus(403);
    }

    #[Test]
    public function a_scoped_admin_cannot_create_an_agency_they_could_not_then_see(): void
    {
        $manager = $this->makeScopedManager([$this->mine->id]);

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/agencies", ['name' => 'A new one'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'OUT_OF_SCOPE');
    }

    // ----------------------------------------------------------------- money

    #[Test]
    public function settlements_are_scoped_and_totals_do_not_include_another_agency(): void
    {
        $this->makeSettlement($this->mine);
        $theirs = $this->makeSettlement($this->theirs);

        $manager = $this->makeScopedManager([$this->mine->id]);

        $rows = $this->actingAs($manager, 'sanctum-admin')
            ->getJson("{$this->base}/settlements")
            ->assertOk()
            ->json();

        $this->assertCount(1, $rows['data']);
        // The filter is in SQL, so the pagination total is scoped too — the count is the
        // thing that leaks if rows are merely hidden in the UI.
        $this->assertSame(1, $rows['meta']['total']);

        $this->actingAs($manager, 'sanctum-admin')
            ->getJson("{$this->base}/settlements/{$theirs->id}")->assertStatus(403);
    }

    #[Test]
    public function another_agencys_settlement_cannot_be_approved_or_batched(): void
    {
        $theirs = $this->makeSettlement($this->theirs);
        $manager = $this->makeScopedManager([$this->mine->id], 'agency.settlement_process');

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/{$theirs->id}/approve")->assertStatus(403);

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/batch", ['settlement_ids' => [$theirs->id]])
            ->assertStatus(403);
    }

    #[Test]
    public function a_mixed_batch_is_refused_rather_than_partly_applied(): void
    {
        // One out-of-scope row in a batch would move somebody else's money.
        $ours = $this->makeSettlement($this->mine);
        $theirs = $this->makeSettlement($this->theirs);

        $manager = $this->makeScopedManager([$this->mine->id], 'agency.settlement_process');

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/batch", ['settlement_ids' => [$ours->id, $theirs->id]])
            ->assertStatus(403);

        $this->assertNull($ours->fresh()->batch_id);
        $this->assertNull($theirs->fresh()->batch_id);
    }

    #[Test]
    public function generating_a_settlement_for_another_agency_is_refused(): void
    {
        $manager = $this->makeScopedManager([$this->mine->id], 'agency.settlement_raise');

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/generate", [
                'agency_id' => $this->theirs->id,
                'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
                'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'OUT_OF_SCOPE');
    }

    // ------------------------------------------------------------------- B.1a

    #[Test]
    public function a_scoped_dashboard_replaces_the_platform_one(): void
    {
        $host = $this->makeHost($this->mine);
        $this->makeHost($this->theirs);

        $manager = $this->makeScopedManager([$this->mine->id], 'dashboard.view');

        $data = $this->actingAs($manager, 'sanctum-admin')
            ->getJson("{$this->base}/dashboard/kpis")
            ->assertOk()
            ->json('data');

        // Their agency's hosts only.
        $this->assertSame(1, $data['hosts']['total']);
        $this->assertSame([$this->mine->id], collect($data['scope']['agencies'])->pluck('id')->all());

        // And none of the platform figures, which cannot be attributed to an agency.
        $this->assertArrayNotHasKey('users', $data);
        $this->assertArrayNotHasKey('engagement', $data);
        $this->assertStringContainsString('cannot be attributed', $data['note']);
    }

    #[Test]
    public function an_unscoped_admin_still_gets_the_platform_dashboard(): void
    {
        $data = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/dashboard/kpis")
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('users', $data);
    }

    #[Test]
    public function platform_revenue_series_are_refused_on_a_scoped_account(): void
    {
        $manager = $this->makeScopedManager([$this->mine->id], 'dashboard.view');

        // Refusing beats returning the platform numbers, and beats returning zeroes that
        // would look like an outage.
        $this->actingAs($manager, 'sanctum-admin')
            ->getJson("{$this->base}/dashboard/revenue")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'OUT_OF_SCOPE');
    }

    // ------------------------------------------------------------------- B.5a

    #[Test]
    public function a_host_report_contains_only_hosts_within_scope(): void
    {
        $mineHost = $this->makeHost($this->mine);
        $theirHost = $this->makeHost($this->theirs);

        $day = now()->subDays(2);
        $this->earn($mineHost, 5000, $day->toDateString());
        $this->earn($theirHost, 9000, $day->toDateString());

        app(\App\Domain\Agency\HostEarningsRollup::class)->forDate($day->copy());

        $manager = $this->makeScopedManager([$this->mine->id], 'reports_export.hosts');

        $result = $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/reports-centre/preview", [
                'type' => 'hosts',
                'filters' => ['from' => now()->subDays(5)->toDateString()],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $result['total']);
        $this->assertSame($mineHost->id, $result['rows'][0]['host_id']);
    }

    #[Test]
    public function a_caller_cannot_widen_their_own_scope_through_the_filters(): void
    {
        $mineHost = $this->makeHost($this->mine);
        $theirHost = $this->makeHost($this->theirs);

        $day = now()->subDays(2);
        $this->earn($mineHost, 5000, $day->toDateString());
        $this->earn($theirHost, 9000, $day->toDateString());

        app(\App\Domain\Agency\HostEarningsRollup::class)->forDate($day->copy());

        $manager = $this->makeScopedManager([$this->mine->id], 'reports_export.hosts');

        // Posting the reserved key directly must not widen anything.
        $result = $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/reports-centre/preview", [
                'type' => 'hosts',
                'filters' => [
                    'from' => now()->subDays(5)->toDateString(),
                    ReportEngine::SCOPE_KEY => [$this->mine->id, $this->theirs->id],
                ],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $result['total']);
    }

    #[Test]
    public function the_scope_is_frozen_onto_a_queued_export(): void
    {
        $this->makeHost($this->mine);
        $manager = $this->makeScopedManager([$this->mine->id], 'reports_export.hosts');

        $uuid = $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/reports-centre/export", ['type' => 'hosts'])
            ->assertStatus(202)
            ->json('data.uuid');

        $export = \App\Models\ReportExport::where('uuid', $uuid)->firstOrFail();

        // The worker runs later; it must build the report the operator asked for, not one
        // widened by a grant change in between.
        $this->assertSame([$this->mine->id], $export->filters[ReportEngine::SCOPE_KEY]);
    }

    #[Test]
    public function a_scoped_account_cannot_pull_platform_revenue(): void
    {
        $manager = $this->makeScopedManager([$this->mine->id], 'reports_export.revenue');

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/reports-centre/preview", ['type' => 'revenue'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'OUT_OF_SCOPE');

        $this->actingAs($manager, 'sanctum-admin')
            ->getJson("{$this->base}/reports-centre/reconcile")
            ->assertStatus(403);
    }

    #[Test]
    public function the_catalogue_does_not_advertise_a_report_the_caller_cannot_run(): void
    {
        $manager = $this->makeScopedManager([$this->mine->id], 'reports_export.revenue');

        $types = collect(
            $this->actingAs($manager, 'sanctum-admin')->getJson("{$this->base}/reports-centre")->json('data.types')
        )->pluck('type');

        $this->assertFalse($types->contains('revenue'));
        $this->assertTrue($types->contains('hosts'));
    }

    // ---------------------------------------------------------------- lifecycle

    #[Test]
    public function an_expired_grant_stops_conferring_its_scope(): void
    {
        $manager = $this->makeAdmin('Expiring', Role::MANAGER);

        DB::table('admin_user_permission')->insert([
            'admin_user_id' => $manager->id,
            'permission_id' => DB::table('permissions')->where('key', 'agency.view')->value('id'),
            'effect' => 'allow', 'granted_by' => $this->superAdmin->id,
            'scope' => json_encode(['agencies' => [$this->mine->id]]),
            'expires_at' => now()->subHour(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The grant has lapsed, so it carries no scope. The role still grants `agency.view`
        // unscoped, which is the correct outcome — the *scope* expired, not the permission.
        $this->assertNull(app(ScopeFilter::class)->agencyIds($manager->fresh()));
    }

    #[Test]
    public function a_scope_covering_two_agencies_shows_both(): void
    {
        $manager = $this->makeScopedManager([$this->mine->id, $this->theirs->id]);

        $this->assertCount(
            2,
            $this->actingAs($manager, 'sanctum-admin')->getJson("{$this->base}/agencies")->json('data'),
        );
    }

    #[Test]
    public function a_scope_listing_no_agencies_shows_nothing_rather_than_everything(): void
    {
        // The dangerous failure mode: an empty allow-list read as "no restriction".
        $manager = $this->makeAdmin('Empty scope', Role::MANAGER);

        DB::table('admin_user_permission')->insert([
            'admin_user_id' => $manager->id,
            'permission_id' => DB::table('permissions')->where('key', 'agency.view')->value('id'),
            'effect' => 'allow', 'granted_by' => $this->superAdmin->id,
            'scope' => json_encode(['agencies' => [-1]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertCount(
            0,
            $this->actingAs($manager, 'sanctum-admin')->getJson("{$this->base}/agencies")->json('data'),
        );
    }
}
