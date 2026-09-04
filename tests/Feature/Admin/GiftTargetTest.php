<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\CoinTransaction;
use App\Models\GiftTargetPolicy;
use App\Models\Host;
use App\Models\HostGiftTargetResult;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The monthly gift-target ladder (mehfil's "Policies", ported) — separate from A.8b's
 * HostTarget, which AgencyHostTest already covers.
 */
class GiftTargetTest extends TestCase
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
            'name' => 'Super', 'email' => 'super@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::SUPER_ADMIN)->value('id'), 'status' => 'active',
        ]);
    }

    protected function makeUser(): User
    {
        static $seq = 0;
        $seq++;

        $user = User::create([
            'guftagu_id' => 'GF'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
            'phone'      => '+9198207'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'status'     => User::STATUS_ACTIVE,
            'agora_uid'  => 700000 + $seq,
        ]);

        UserProfile::create(['user_id' => $user->id, 'display_name' => "Host {$seq}"]);
        Wallet::create(['user_id' => $user->id]);

        return $user;
    }

    protected function makeAgency(): Agency
    {
        static $seq = 0;
        $seq++;

        return Agency::create([
            'code' => 'AGY-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'name' => "Agency {$seq}",
            'documents' => [['type' => 'gst', 'url' => 'https://cdn.example.com/g.pdf']],
            'commission_bp' => 1500,
            'status' => Agency::APPROVED,
        ]);
    }

    protected function makeHost(?Agency $agency = null): Host
    {
        return Host::create([
            'user_id' => $this->makeUser()->id,
            'agency_id' => $agency?->id,
            'status' => Host::APPROVED,
            'applied_at' => now()->subMonth(),
        ]);
    }

    /** A coin debit for sending a gift, backdated into a specific month. */
    protected function spendCoins(Host $host, int $amount, string $isoDay): void
    {
        $wallet = Wallet::where('user_id', $host->user_id)->firstOrFail();
        $before = (int) CoinTransaction::where('user_id', $host->user_id)->max('balance_after');

        DB::table('coin_transactions')->insert([
            'uuid' => (string) Str::uuid(),
            'wallet_id' => $wallet->id,
            'user_id' => $host->user_id,
            'direction' => 'debit',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => max(0, $before - $amount),
            'type' => 'gift_sent',
            'created_at' => $isoDay.' 12:00:00',
        ]);
    }

    /** Seated time in a room, backdated into a specific month. */
    protected function goLive(Host $host, int $minutes, string $isoDay): void
    {
        $room = Room::create([
            'room_code' => 'RM'.Str::random(6),
            'owner_id' => $host->user_id,
            'name' => 'Test room',
            'seat_count' => 8,
            'status' => Room::LIVE,
        ]);

        RoomMember::create([
            'room_id' => $room->id,
            'user_id' => $host->user_id,
            'role' => 'owner',
            'joined_at' => $isoDay.' 10:00:00',
            'left_at' => $isoDay.' '.sprintf('%02d', 10 + intdiv($minutes, 60)).':'.sprintf('%02d', $minutes % 60).':00',
            'duration_seconds' => $minutes * 60,
            'is_active' => false,
        ]);
    }

    // -------------------------------------------------------------- the ladder

    #[Test]
    public function a_policy_can_be_created_and_listed(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/gift-target-policies", [
                'time_minutes' => 60, 'target_coins' => 10000,
                'host_reward_paise' => 30000, 'agency_reward_paise' => 20000,
            ])
            ->assertStatus(201);

        $this->assertTrue(GiftTargetPolicy::where('target_coins', 10000)->exists());

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/gift-target-policies")
            ->assertOk()
            ->assertJsonFragment(['target_coins' => 10000]);
    }

    #[Test]
    public function the_ladder_needs_its_own_permission(): void
    {
        $moderator = AdminUser::create([
            'name' => 'Mod', 'email' => 'mod@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::MODERATOR)->value('id'), 'status' => 'active',
        ]);

        $this->actingAs($moderator, 'sanctum-admin')
            ->getJson("{$this->base}/gift-target-policies")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    // ------------------------------------------------------------- evaluation

    #[Test]
    public function a_host_who_clears_both_thresholds_achieves_the_tier_and_pays_their_agency(): void
    {
        $agency = $this->makeAgency();
        $host = $this->makeHost($agency);

        GiftTargetPolicy::create(['time_minutes' => 60, 'target_coins' => 10000, 'host_reward_paise' => 30000, 'agency_reward_paise' => 20000]);

        $this->spendCoins($host, 12000, '2026-09-05');
        $this->goLive($host, 90, '2026-09-06');

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/{$host->id}/gift-targets/evaluate", ['period' => '2026-09'])
            ->assertOk();

        $this->assertSame(12000, $response->json('data.coins_sent'));
        $this->assertSame(90, $response->json('data.minutes_live'));
        $this->assertSame(30000, $response->json('data.host_reward_paise'));
        $this->assertSame(20000, $response->json('data.agency_reward_paise'));
        $this->assertNotNull($response->json('data.evaluated_at'));

        $this->assertTrue(AuditLog::where('action', 'host_gift_target.evaluate')->exists());
    }

    #[Test]
    public function a_host_with_no_agency_never_generates_an_agency_reward(): void
    {
        $host = $this->makeHost(null);

        GiftTargetPolicy::create(['time_minutes' => 0, 'target_coins' => 1000, 'host_reward_paise' => 5000, 'agency_reward_paise' => 3000]);

        $this->spendCoins($host, 1000, '2026-09-05');

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/{$host->id}/gift-targets/evaluate", ['period' => '2026-09'])
            ->assertOk();

        $this->assertSame(5000, $response->json('data.host_reward_paise'));
        $this->assertSame(0, $response->json('data.agency_reward_paise'));
    }

    #[Test]
    public function only_clearing_the_coin_threshold_without_the_time_threshold_misses_the_tier(): void
    {
        $host = $this->makeHost();

        GiftTargetPolicy::create(['time_minutes' => 120, 'target_coins' => 10000, 'host_reward_paise' => 30000, 'agency_reward_paise' => 20000]);

        $this->spendCoins($host, 50000, '2026-09-05');
        // No live time at all.

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/{$host->id}/gift-targets/evaluate", ['period' => '2026-09'])
            ->assertOk();

        $this->assertNull($response->json('data.policy_id'));
        $this->assertSame(0, $response->json('data.host_reward_paise'));
    }

    #[Test]
    public function the_highest_cleared_tier_wins_not_the_first_defined(): void
    {
        $host = $this->makeHost();

        GiftTargetPolicy::create(['time_minutes' => 0, 'target_coins' => 1000, 'host_reward_paise' => 5000, 'agency_reward_paise' => 0]);
        GiftTargetPolicy::create(['time_minutes' => 0, 'target_coins' => 5000, 'host_reward_paise' => 15000, 'agency_reward_paise' => 0]);
        GiftTargetPolicy::create(['time_minutes' => 0, 'target_coins' => 50000, 'host_reward_paise' => 100000, 'agency_reward_paise' => 0]);

        $this->spendCoins($host, 6000, '2026-09-05');

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/{$host->id}/gift-targets/evaluate", ['period' => '2026-09'])
            ->assertOk();

        $this->assertSame(15000, $response->json('data.host_reward_paise'), 'The 5,000-coin tier is the highest cleared by 6,000 coins.');
    }

    #[Test]
    public function evaluating_the_same_host_and_month_twice_is_refused(): void
    {
        $host = $this->makeHost();
        GiftTargetPolicy::create(['time_minutes' => 0, 'target_coins' => 100, 'host_reward_paise' => 1000, 'agency_reward_paise' => 0]);
        $this->spendCoins($host, 100, '2026-09-05');

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/{$host->id}/gift-targets/evaluate", ['period' => '2026-09'])
            ->assertOk();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/{$host->id}/gift-targets/evaluate", ['period' => '2026-09'])
            ->assertStatus(400);
    }

    #[Test]
    public function coins_sent_outside_the_target_month_do_not_count(): void
    {
        $host = $this->makeHost();
        GiftTargetPolicy::create(['time_minutes' => 0, 'target_coins' => 1000, 'host_reward_paise' => 5000, 'agency_reward_paise' => 0]);

        $this->spendCoins($host, 5000, '2026-08-31');
        $this->spendCoins($host, 200, '2026-09-01');

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/{$host->id}/gift-targets/evaluate", ['period' => '2026-09'])
            ->assertOk();

        $this->assertSame(200, $response->json('data.coins_sent'));
        $this->assertNull($response->json('data.policy_id'));
    }

    #[Test]
    public function evaluate_all_processes_every_host_and_skips_already_done_ones(): void
    {
        $agency = $this->makeAgency();
        $hostA = $this->makeHost($agency);
        $hostB = $this->makeHost($agency);

        GiftTargetPolicy::create(['time_minutes' => 0, 'target_coins' => 100, 'host_reward_paise' => 1000, 'agency_reward_paise' => 500]);
        $this->spendCoins($hostA, 500, '2026-09-05');
        $this->spendCoins($hostB, 500, '2026-09-05');

        // Host A already evaluated this month; only B should be freshly processed.
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/{$hostA->id}/gift-targets/evaluate", ['period' => '2026-09'])
            ->assertOk();

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/gift-targets/evaluate-all", ['period' => '2026-09'])
            ->assertOk();

        $this->assertSame(1, $response->json('data.evaluated'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.skipped'));
        $this->assertTrue(HostGiftTargetResult::where('host_id', $hostB->id)->where('period', '2026-09')->exists());
    }

    #[Test]
    public function results_are_scoped_to_a_managers_own_agencies(): void
    {
        $ownAgency = $this->makeAgency();
        $otherAgency = $this->makeAgency();
        $ownHost = $this->makeHost($ownAgency);
        $otherHost = $this->makeHost($otherAgency);

        GiftTargetPolicy::create(['time_minutes' => 0, 'target_coins' => 100, 'host_reward_paise' => 1000, 'agency_reward_paise' => 0]);
        $this->spendCoins($ownHost, 500, '2026-09-05');
        $this->spendCoins($otherHost, 500, '2026-09-05');

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/gift-targets/evaluate-all", ['period' => '2026-09'])
            ->assertOk();

        $manager = AdminUser::create([
            'name' => 'Manager', 'email' => 'manager@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::MANAGER)->value('id'), 'status' => 'active',
        ]);

        DB::table('admin_user_permission')->insert([
            'admin_user_id' => $manager->id,
            'permission_id' => DB::table('permissions')->where('key', 'hosts.gift_target_manage')->value('id'),
            'effect'        => 'allow',
            'granted_by'    => $this->superAdmin->id,
            'scope'         => json_encode(['agencies' => [$ownAgency->id]]),
            'created_at'    => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($manager, 'sanctum-admin')
            ->getJson("{$this->base}/hosts/gift-targets?period=2026-09")
            ->assertOk();

        $hostIds = collect($response->json('data'))->pluck('host.id')->all();
        $this->assertContains($ownHost->id, $hostIds);
        $this->assertNotContains($otherHost->id, $hostIds);
    }

    // -------------------------------------------------------------- tracker

    #[Test]
    public function the_tracker_shows_live_progress_against_the_lowest_uncleared_rung(): void
    {
        $host = $this->makeHost();

        GiftTargetPolicy::create(['time_minutes' => 0, 'target_coins' => 1000, 'host_reward_paise' => 1000, 'agency_reward_paise' => 0]);
        GiftTargetPolicy::create(['time_minutes' => 60, 'target_coins' => 5000, 'host_reward_paise' => 3000, 'agency_reward_paise' => 0]);

        // Clears the first rung, partway into the second.
        $this->spendCoins($host, 2500, now()->format('Y-m').'-01');
        $this->goLive($host, 30, now()->format('Y-m').'-02');

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/hosts/gift-targets/tracker")
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('host.id', $host->id);

        $this->assertFalse($row['is_frozen']);
        $this->assertSame('derived live from ledger', $row['source']);
        $this->assertSame(5000, $row['target']['target_coins'], 'The 1,000-coin rung is already cleared — tracking the next one.');
        $this->assertSame(50, $row['coins_pct'], '2,500 of 5,000 coins.');
        $this->assertSame(50, $row['minutes_pct'], '30 of 60 minutes.');
        $this->assertSame(50, $row['overall_pct']);
    }

    #[Test]
    public function the_tracker_shows_the_frozen_result_once_evaluated_instead_of_live_progress(): void
    {
        $host = $this->makeHost();
        GiftTargetPolicy::create(['time_minutes' => 0, 'target_coins' => 100, 'host_reward_paise' => 500, 'agency_reward_paise' => 0]);
        $this->spendCoins($host, 100, now()->format('Y-m').'-01');

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/hosts/{$host->id}/gift-targets/evaluate", ['period' => now()->format('Y-m')])
            ->assertOk();

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/hosts/gift-targets/tracker")
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('host.id', $host->id);

        $this->assertTrue($row['is_frozen']);
        $this->assertSame('frozen at evaluation', $row['source']);
        $this->assertSame(500, $row['host_reward_paise']);
    }

    #[Test]
    public function a_host_with_no_active_rungs_configured_shows_no_target_rather_than_an_error(): void
    {
        $this->makeHost();

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/hosts/gift-targets/tracker")
            ->assertOk();

        $this->assertNull($response->json('data.0.target'));
        $this->assertNull($response->json('data.0.overall_pct'));
    }
}
