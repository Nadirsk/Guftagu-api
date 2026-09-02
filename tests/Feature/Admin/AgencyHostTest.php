<?php

namespace Tests\Feature\Admin;

use App\Domain\Agency\HostEarningsRollup;
use App\Domain\Agency\SettlementService;
use App\Domain\Agency\TargetService;
use App\Models\AdminUser;
use App\Models\Agency;
use App\Models\CommissionSlab;
use App\Models\DiamondTransaction;
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

/** Epic A.8 acceptance criteria — agencies, hosts, targets and settlements. */
class AgencyHostTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected AdminUser $superAdmin;

    protected AdminUser $secondAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class, EconomySeeder::class]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->superAdmin = $this->makeAdmin('Super', Role::SUPER_ADMIN);
        $this->secondAdmin = $this->makeAdmin('Second', Role::SUPER_ADMIN);
    }

    protected function makeAdmin(string $name, string $roleKey): AdminUser
    {
        static $seq = 0;
        $seq++;

        return AdminUser::create([
            'name' => $name, 'email' => "admin{$seq}@test.local", 'password' => 'Password12345',
            'role_id' => Role::where('key', $roleKey)->value('id'), 'status' => 'active',
        ]);
    }

    protected function makeUser(): User
    {
        static $seq = 0;
        $seq++;

        $user = User::create([
            'guftagu_id' => 'GF'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
            'phone'      => '+9198206'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'status'     => User::STATUS_ACTIVE,
            'agora_uid'  => 600000 + $seq,
        ]);

        UserProfile::create(['user_id' => $user->id, 'display_name' => "Host {$seq}"]);
        Wallet::create(['user_id' => $user->id]);

        return $user;
    }

    protected function makeAgency(string $status = Agency::APPROVED, array $attributes = []): Agency
    {
        static $seq = 0;
        $seq++;

        return Agency::create(array_merge([
            'code'          => 'AGY-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'name'          => "Agency {$seq}",
            'documents'     => [['type' => 'gst', 'url' => 'https://cdn.example.com/g.pdf']],
            'commission_bp' => 1500,
            'status'        => $status,
        ], $attributes));
    }

    protected function makeHost(?Agency $agency = null, string $status = Host::APPROVED): Host
    {
        return Host::create([
            'user_id'   => $this->makeUser()->id,
            'agency_id' => $agency?->id,
            'status'    => $status,
            'applied_at' => now()->subMonth(),
        ]);
    }

    /**
     * Credit gift diamonds to a host on a given day, chained properly like the real ledger.
     *
     * Inserted through the query builder rather than the model on purpose. `created_at` is
     * not fillable, so `create()` silently drops it and every credit lands today — and the
     * model refuses updates outright, because ledger rows are immutable (docs/02 §15
     * rule 3). Backdating a fixture is the one legitimate reason to go around it.
     */
    protected function earn(Host $host, int $amount, string $day): void
    {
        $wallet = Wallet::where('user_id', $host->user_id)->firstOrFail();
        $before = (int) DiamondTransaction::where('user_id', $host->user_id)->max('balance_after');

        DB::table('diamond_transactions')->insert([
            'uuid'           => (string) Str::uuid(),
            'wallet_id'      => $wallet->id,
            'user_id'        => $host->user_id,
            'direction'      => 'credit',
            'amount'         => $amount,
            'balance_before' => $before,
            'balance_after'  => $before + $amount,
            'type'           => 'gift_received',
            'created_at'     => $day.' 12:00:00',
        ]);
    }

    // -------------------------------------------------------------------- A.8a

    #[Test]
    public function approving_an_agency_makes_it_selectable_by_applicants(): void
    {
        $agency = $this->makeAgency(Agency::PENDING);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/agencies/{$agency->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Agency::APPROVED);

        $this->assertTrue($agency->fresh()->isApproved());
        $this->assertNotNull($agency->fresh()->approved_at);

        // "Selectable by host applicants" is what approval buys — assert the consequence,
        // not just the column.
        $application = HostApplication::create([
            'user_id' => $this->makeUser()->id, 'agency_id' => $agency->id,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/host-applications/{$application->id}/approve", ['agency_id' => $agency->id])
            ->assertOk();
    }

    #[Test]
    public function an_agency_with_no_documents_cannot_be_approved(): void
    {
        $agency = $this->makeAgency(Agency::PENDING, ['documents' => null]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/agencies/{$agency->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DOCUMENTS_MISSING');
    }

    #[Test]
    public function rejecting_an_agency_requires_a_reason(): void
    {
        $agency = $this->makeAgency(Agency::PENDING);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/agencies/{$agency->id}/reject", [])
            ->assertStatus(422);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/agencies/{$agency->id}/reject", ['reason' => 'GST certificate is expired.'])
            ->assertOk();

        $this->assertSame('GST certificate is expired.', $agency->fresh()->rejection_reason);
    }

    #[Test]
    public function hosts_cannot_be_assigned_to_an_agency_that_is_not_approved(): void
    {
        $pending = $this->makeAgency(Agency::PENDING);
        $host = $this->makeHost();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/{$host->id}/agency", ['agency_id' => $pending->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'AGENCY_NOT_APPROVED');
    }

    #[Test]
    public function suspending_an_agency_does_not_suspend_its_hosts(): void
    {
        // Cutting off a host because their agency is under review punishes the wrong
        // person, so this is deliberate and worth pinning down.
        $agency = $this->makeAgency();
        $host = $this->makeHost($agency);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/agencies/{$agency->id}/suspend", ['reason' => 'Under review.'])
            ->assertOk()
            ->assertJsonPath('data.hosts_affected', false);

        $this->assertSame(Host::APPROVED, $host->fresh()->status);
    }

    #[Test]
    public function a_returning_host_reuses_their_row_rather_than_splitting_their_history(): void
    {
        $agency = $this->makeAgency();
        $host = $this->makeHost($agency, Host::LEFT);

        $application = HostApplication::create(['user_id' => $host->user_id, 'agency_id' => $agency->id]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/host-applications/{$application->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.host_id', $host->id);

        $this->assertSame(1, Host::where('user_id', $host->user_id)->count());
    }

    #[Test]
    public function reassigning_a_host_closes_the_old_membership_instead_of_editing_it(): void
    {
        $first = $this->makeAgency();
        $second = $this->makeAgency();
        $host = $this->makeHost($first);

        DB::table('agency_members')->insert([
            'agency_id' => $first->id, 'user_id' => $host->user_id, 'role' => 'host',
            'joined_at' => now()->subMonth(), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/{$host->id}/agency", ['agency_id' => $second->id])
            ->assertOk();

        // Which agency a host belonged to *during a period* is what a settlement is priced
        // from, so the old row survives with an end date.
        $this->assertDatabaseHas('agency_members', [
            'agency_id' => $first->id, 'user_id' => $host->user_id, 'is_active' => false,
        ]);
        $this->assertDatabaseHas('agency_members', [
            'agency_id' => $second->id, 'user_id' => $host->user_id, 'is_active' => true,
        ]);
    }

    #[Test]
    public function an_expired_contract_reads_as_expired_with_no_job_having_run(): void
    {
        $host = $this->makeHost();
        $host->forceFill(['contract_end' => now()->subDay()->toDateString()])->save();

        $this->assertFalse($host->fresh()->isUnderContract());

        $row = collect(
            $this->actingAs($this->superAdmin, 'sanctum-admin')->getJson("{$this->base}/hosts")->json('data')
        )->firstWhere('id', $host->id);

        $this->assertFalse($row['under_contract']);
        // The status column is untouched; only the derived field moved.
        $this->assertSame(Host::APPROVED, $row['status']);
    }

    // -------------------------------------------------------------------- A.8b

    #[Test]
    public function a_host_earning_75_percent_of_target_shows_75_percent(): void
    {
        $host = $this->makeHost($this->makeAgency());

        $target = HostTarget::create([
            'host_id'         => $host->id,
            'period_start'    => now()->startOfMonth()->toDateString(),
            'period_end'      => now()->endOfMonth()->toDateString(),
            'target_diamonds' => 100000,
            'status'          => HostTarget::ACTIVE,
        ]);

        $this->earn($host, 75000, now()->startOfMonth()->addDay()->toDateString());
        app(HostEarningsRollup::class)->forDate(now()->startOfMonth()->addDay());

        $progress = app(TargetService::class)->progress($target);

        $this->assertSame(75000, $progress['achieved_diamonds']);
        $this->assertSame(75, $progress['achievement_pct']);
    }

    #[Test]
    public function the_incentive_comes_from_the_slab_covering_the_achievement(): void
    {
        // Bands keyed on achievement percentage, exactly as A.8b describes them.
        foreach ([[0, 74, 250], [75, 99, 500], [100, null, 1000]] as [$min, $max, $bp]) {
            CommissionSlab::create([
                'applies_to' => 'host', 'metric' => 'diamonds_earned',
                'min_value' => $min, 'max_value' => $max, 'percentage_bp' => $bp,
                'effective_from' => now()->subYear(),
            ]);
        }

        $host = $this->makeHost($this->makeAgency());
        $start = now()->subMonth()->startOfMonth();

        $target = HostTarget::create([
            'host_id'         => $host->id,
            'period_start'    => $start->toDateString(),
            'period_end'      => $start->copy()->endOfMonth()->toDateString(),
            'target_diamonds' => 100000,
            'status'          => HostTarget::ACTIVE,
        ]);

        $this->earn($host, 75000, $start->copy()->addDay()->toDateString());
        app(HostEarningsRollup::class)->forDate($start->copy()->addDay());

        $evaluated = app(TargetService::class)->evaluate($target->fresh(), $this->superAdmin);

        $this->assertSame(75, $evaluated->achievement_pct);
        // 75% lands in the 75–99 band, not the one below it.
        $this->assertSame(500, $evaluated->incentive_bp);
        $this->assertSame(HostTarget::MISSED, $evaluated->status);
    }

    #[Test]
    public function achievement_only_counts_metrics_that_were_actually_set(): void
    {
        // Averaging in a zero for an unset hours target would report 50% for a host who
        // hit their diamond number exactly.
        $host = $this->makeHost();

        $target = HostTarget::create([
            'host_id'         => $host->id,
            'period_start'    => now()->startOfMonth()->toDateString(),
            'period_end'      => now()->endOfMonth()->toDateString(),
            'target_diamonds' => 1000,
            'target_hours'    => 0,
            'target_days'     => 0,
            'status'          => HostTarget::ACTIVE,
        ]);

        $this->earn($host, 1000, now()->startOfMonth()->addDay()->toDateString());
        app(HostEarningsRollup::class)->forDate(now()->startOfMonth()->addDay());

        $this->assertSame(100, app(TargetService::class)->progress($target)['achievement_pct']);
    }

    #[Test]
    public function overlapping_targets_are_refused(): void
    {
        $host = $this->makeHost();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/{$host->id}/targets", [
                'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'target_diamonds' => 1000,
            ])->assertCreated();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/{$host->id}/targets", [
                'period_start' => '2026-09-15', 'period_end' => '2026-10-15', 'target_diamonds' => 1000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PERIOD_OVERLAP');
    }

    #[Test]
    public function an_evaluated_target_is_frozen_and_stops_moving(): void
    {
        $host = $this->makeHost();
        $start = now()->subMonth()->startOfMonth();

        $target = HostTarget::create([
            'host_id' => $host->id, 'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'target_diamonds' => 1000, 'status' => HostTarget::ACTIVE,
        ]);

        $this->earn($host, 500, $start->copy()->addDay()->toDateString());
        app(HostEarningsRollup::class)->forDate($start->copy()->addDay());

        app(TargetService::class)->evaluate($target->fresh(), $this->superAdmin);

        // A late credit after evaluation must not move an incentive somebody was told.
        $this->earn($host, 500, $start->copy()->addDays(2)->toDateString());
        app(HostEarningsRollup::class)->forDate($start->copy()->addDays(2));

        $payload = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/hosts/targets/{$target->id}")->json('data');

        $this->assertTrue($payload['is_frozen']);
        $this->assertSame(50, $payload['achievement_pct']);
        $this->assertSame('frozen at evaluation', $payload['source']);
    }

    #[Test]
    public function an_open_target_reports_live_figures_not_a_stale_column(): void
    {
        $host = $this->makeHost();

        $target = HostTarget::create([
            'host_id' => $host->id, 'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'target_diamonds' => 1000, 'status' => HostTarget::ACTIVE,
        ]);

        $this->earn($host, 400, now()->startOfMonth()->addDay()->toDateString());
        app(HostEarningsRollup::class)->forDate(now()->startOfMonth()->addDay());

        $payload = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/hosts/targets/{$target->id}")->json('data');

        $this->assertFalse($payload['is_frozen']);
        $this->assertSame(40, $payload['achievement_pct']);
        $this->assertSame('derived live from host_earnings', $payload['source']);
    }

    #[Test]
    public function an_evaluated_target_cannot_be_cancelled(): void
    {
        $host = $this->makeHost();
        $start = now()->subMonth()->startOfMonth();

        $target = HostTarget::create([
            'host_id' => $host->id, 'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'target_diamonds' => 1000, 'status' => HostTarget::ACTIVE,
        ]);

        app(TargetService::class)->evaluate($target, $this->superAdmin);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->deleteJson("{$this->base}/hosts/targets/{$target->id}")
            ->assertStatus(400);
    }

    // -------------------------------------------------------------------- A.8c

    #[Test]
    public function the_rollup_equals_the_ledger_over_a_range_exactly(): void
    {
        $host = $this->makeHost($this->makeAgency());
        $start = now()->subDays(10);

        $amounts = [1234, 5678, 91011, 42, 7];

        foreach ($amounts as $offset => $amount) {
            $this->earn($host, $amount, $start->copy()->addDays($offset)->toDateString());
        }

        app(HostEarningsRollup::class)->forRange($start->copy(), now());

        $result = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/hosts/{$host->id}/earnings/verify?from={$start->toDateString()}&to=".now()->toDateString())
            ->assertOk()
            ->json('data');

        $this->assertTrue($result['matches']);
        $this->assertSame(array_sum($amounts), $result['rollup_diamonds']);
        $this->assertSame(0, $result['difference']);
    }

    #[Test]
    public function rerunning_the_rollup_does_not_double_count(): void
    {
        $host = $this->makeHost();
        $day = now()->subDays(3);

        $this->earn($host, 5000, $day->toDateString());

        $rollup = app(HostEarningsRollup::class);
        $rollup->forDate($day->copy());
        $rollup->forDate($day->copy());
        $rollup->forDate($day->copy());

        $this->assertDatabaseHas('host_earnings', [
            'host_id' => $host->id, 'date' => $day->toDateString(), 'diamonds_earned' => 5000,
        ]);
        $this->assertSame(1, DB::table('host_earnings')->where('host_id', $host->id)->count());
    }

    #[Test]
    public function a_late_credit_corrects_the_day_rather_than_adding_to_it(): void
    {
        $host = $this->makeHost();
        $day = now()->subDays(3);

        $this->earn($host, 1000, $day->toDateString());
        app(HostEarningsRollup::class)->forDate($day->copy());

        $this->earn($host, 500, $day->toDateString());
        app(HostEarningsRollup::class)->forDate($day->copy());

        $this->assertDatabaseHas('host_earnings', [
            'host_id' => $host->id, 'date' => $day->toDateString(), 'diamonds_earned' => 1500,
        ]);
    }

    #[Test]
    public function the_three_cuts_always_add_back_to_gross(): void
    {
        $agency = $this->makeAgency(Agency::APPROVED, ['commission_bp' => 1337]);
        $host = $this->makeHost($agency);

        // Deliberately awkward amounts: if truncation leaked, these are where it shows.
        foreach ([7, 13, 999, 1001, 33333] as $offset => $amount) {
            $this->earn($host, $amount, now()->subDays(10 - $offset)->toDateString());
        }

        app(HostEarningsRollup::class)->forRange(now()->subDays(10), now());

        $rows = DB::table('host_earnings')->where('host_id', $host->id)->get();

        $this->assertGreaterThan(0, $rows->count());

        foreach ($rows as $row) {
            $this->assertSame(
                (int) $row->gross_paise,
                (int) $row->platform_cut_paise + (int) $row->agency_cut_paise + (int) $row->net_paise,
                "The split leaked on {$row->date}.",
            );
        }
    }

    #[Test]
    public function a_missing_platform_slab_leaves_the_day_unpriced_rather_than_overpaying_the_host(): void
    {
        // Defaulting a missing platform rate to 0% would hand the whole gross to the host,
        // because the host takes the remainder.
        CommissionSlab::where('applies_to', 'platform')->delete();

        $host = $this->makeHost($this->makeAgency());
        $day = now()->subDays(2);

        $this->earn($host, 10000, $day->toDateString());

        $result = app(HostEarningsRollup::class)->forDate($day->copy());

        $this->assertFalse($result['priced']);
        $this->assertDatabaseHas('host_earnings', [
            'host_id' => $host->id, 'date' => $day->toDateString(),
            'diamonds_earned' => 10000, 'net_paise' => 0, 'gross_paise' => 0,
        ]);
    }

    #[Test]
    public function unique_gifters_is_null_rather_than_zero(): void
    {
        $host = $this->makeHost();
        $this->earn($host, 100, now()->subDay()->toDateString());
        app(HostEarningsRollup::class)->forDate(now()->subDay());

        $payload = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/hosts/{$host->id}?from=".now()->subDays(5)->toDateString())
            ->assertOk()
            ->json('data');

        $this->assertNull($payload['totals']['unique_gifters']);
        $this->assertStringContainsString('gift_transactions', $payload['note']);
    }

    // -------------------------------------------------------------------- A.8d

    #[Test]
    public function a_settlement_is_generated_approved_batched_and_paid_once(): void
    {
        $agency = $this->makeAgency();
        $host = $this->makeHost($agency);

        $this->earn($host, 500000, now()->subDays(5)->toDateString());
        app(HostEarningsRollup::class)->forRange(now()->subDays(10), now());

        $settlement = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/generate", [
                'agency_id'    => $agency->id,
                'period_start' => now()->subDays(10)->toDateString(),
                'period_end'   => now()->toDateString(),
            ])
            ->assertOk()
            ->json('data');

        $this->assertTrue($settlement['splits_balance']);
        $this->assertGreaterThan(0, $settlement['net_payable_paise']);

        $this->actingAs($this->secondAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/{$settlement['id']}/approve")
            ->assertOk();

        $batch = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/batch", ['settlement_ids' => [$settlement['id']]])
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $batch['count']);
        $this->assertSame($settlement['net_payable_paise'], $batch['total_paise']);

        $first = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/batches/{$batch['batch_id']}/process")
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $first['newly_paid']);
        $this->assertSame(Settlement::PAID, Settlement::find($settlement['id'])->status);

        // A.8d: re-processing must not pay twice, and the total must not move.
        $second = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/batches/{$batch['batch_id']}/process")
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $second['newly_paid']);
        $this->assertSame(1, $second['already_paid']);
        $this->assertSame($first['total_paise'], $second['total_paise']);
        $this->assertSame(1, $second['count']);
    }

    #[Test]
    public function regenerating_a_period_updates_the_draft_instead_of_creating_a_second_claim(): void
    {
        $agency = $this->makeAgency();
        $this->makeHost($agency);

        $payload = [
            'agency_id'    => $agency->id,
            'period_start' => now()->subDays(10)->toDateString(),
            'period_end'   => now()->toDateString(),
        ];

        $first = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/generate", $payload)->json('data.id');

        $second = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/generate", $payload)->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Settlement::where('agency_id', $agency->id)->count());
    }

    #[Test]
    public function a_raised_settlement_cannot_be_silently_regenerated(): void
    {
        $agency = $this->makeAgency();
        $this->makeHost($agency);

        $payload = [
            'agency_id'    => $agency->id,
            'period_start' => now()->subDays(10)->toDateString(),
            'period_end'   => now()->toDateString(),
        ];

        $id = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/generate", $payload)->json('data.id');

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/{$id}/raise", ['notes' => 'Ready.'])->assertOk();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/generate", $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ALREADY_RAISED');
    }

    #[Test]
    public function whoever_raised_a_settlement_cannot_approve_it(): void
    {
        $agency = $this->makeAgency();
        $this->makeHost($agency);

        $id = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/generate", [
                'agency_id' => $agency->id,
                'period_start' => now()->subDays(10)->toDateString(),
                'period_end' => now()->toDateString(),
            ])->json('data.id');

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/{$id}/raise", [])->assertOk();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/{$id}/approve")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'SELF_APPROVAL');

        $this->actingAs($this->secondAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/{$id}/approve")
            ->assertOk();
    }

    #[Test]
    public function an_unapproved_settlement_cannot_be_batched(): void
    {
        $agency = $this->makeAgency();
        $this->makeHost($agency);

        $id = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/generate", [
                'agency_id' => $agency->id,
                'period_start' => now()->subDays(10)->toDateString(),
                'period_end' => now()->toDateString(),
            ])->json('data.id');

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/batch", ['settlement_ids' => [$id]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NOT_APPROVED');
    }

    #[Test]
    public function a_settlement_cannot_be_batched_twice(): void
    {
        $agency = $this->makeAgency();
        $this->makeHost($agency);

        $id = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/generate", [
                'agency_id' => $agency->id,
                'period_start' => now()->subDays(10)->toDateString(),
                'period_end' => now()->toDateString(),
            ])->json('data.id');

        $this->actingAs($this->secondAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/{$id}/approve")->assertOk();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/batch", ['settlement_ids' => [$id]])->assertOk();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/batch", ['settlement_ids' => [$id]])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ALREADY_BATCHED');
    }

    #[Test]
    public function a_settlement_freezes_the_rate_it_was_generated_at(): void
    {
        $agency = $this->makeAgency();
        $this->makeHost($agency);

        $data = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/generate", [
                'agency_id' => $agency->id,
                'period_start' => now()->subDays(10)->toDateString(),
                'period_end' => now()->toDateString(),
            ])->json('data');

        // Approving next week must still settle at this period's price.
        $this->assertNotNull($data['rate']);
        $this->assertSame(50, $data['rate']['numerator']);
    }

    #[Test]
    public function a_suspended_agency_is_not_settled(): void
    {
        $agency = $this->makeAgency(Agency::SUSPENDED);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/generate", [
                'agency_id' => $agency->id,
                'period_start' => now()->subDays(10)->toDateString(),
                'period_end' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'AGENCY_NOT_APPROVED');
    }

    // ------------------------------------------------------------- permissions

    #[Test]
    public function raising_and_approving_a_settlement_are_separately_permissioned(): void
    {
        $manager = $this->makeAdmin('Manager', Role::MANAGER);
        $agency = $this->makeAgency();
        $this->makeHost($agency);

        // A Manager may raise (docs/02 §5) …
        $id = $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/generate", [
                'agency_id' => $agency->id,
                'period_start' => now()->subDays(10)->toDateString(),
                'period_end' => now()->toDateString(),
            ])
            ->assertOk()
            ->json('data.id');

        // … but not approve.
        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/settlements/{$id}/approve")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    #[Test]
    public function a_moderator_cannot_reach_any_agency_route(): void
    {
        $moderator = $this->makeAdmin('Mod', Role::MODERATOR);
        $agency = $this->makeAgency();

        $this->actingAs($moderator, 'sanctum-admin')->getJson("{$this->base}/agencies")->assertStatus(403);
        $this->actingAs($moderator, 'sanctum-admin')->getJson("{$this->base}/hosts")->assertStatus(403);
        $this->actingAs($moderator, 'sanctum-admin')->getJson("{$this->base}/settlements")->assertStatus(403);
        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson("{$this->base}/agencies/{$agency->id}/approve")->assertStatus(403);
    }
}
