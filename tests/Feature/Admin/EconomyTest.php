<?php

namespace Tests\Feature\Admin;

use App\Domain\Economy\EconomyException;
use App\Domain\Economy\RateResolver;
use App\Domain\Economy\Reconciler;
use App\Domain\Economy\WithdrawalService;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\WalletService;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\CommissionSlab;
use App\Models\DiamondTransaction;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Database\Seeders\EconomySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Epic A.7 acceptance criteria. */
class EconomyTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected AdminUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class, EconomySeeder::class]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->superAdmin = $this->makeAdmin(Role::SUPER_ADMIN);
        Cache::flush();
    }

    protected function makeAdmin(string $roleKey, string $email = null): AdminUser
    {
        return AdminUser::create([
            'name'     => ucfirst($roleKey),
            'email'    => $email ?? $roleKey.'@test.local',
            'password' => 'Password12345',
            'role_id'  => Role::where('key', $roleKey)->value('id'),
            'status'   => 'active',
        ]);
    }

    protected function makeUserWithDiamonds(int $diamonds): User
    {
        static $seq = 0;
        $seq++;

        $user = User::create([
            'guftagu_id' => 'GF'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
            'phone'      => '+9198203'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'status'     => User::STATUS_ACTIVE,
            'agora_uid'  => 500000 + $seq,
        ]);

        UserProfile::create(['user_id' => $user->id, 'display_name' => "Earner {$seq}"]);

        if ($diamonds > 0) {
            app(WalletService::class)->adjust($user, 'diamond', 'credit', $diamonds, 'seed', $this->superAdmin);
        }

        return $user;
    }

    // -------------------------------------------------------------------- A.7a

    #[Test]
    public function a_rate_is_a_fraction_and_converts_with_integer_arithmetic(): void
    {
        $rates = app(RateResolver::class);
        $rate = $rates->require(RateResolver::DIAMOND_TO_INR);

        // 50 paise per diamond, exactly — no floating point anywhere in the path.
        $this->assertSame(50, $rate->rate_numerator);
        $this->assertSame(1, $rate->rate_denominator);
        $this->assertSame(50_000, $rates->convert(1000, $rate));

        // A fraction that has no exact decimal still converts to a whole number of paise.
        $third = $rates->set('test_third', 1, 3);
        $this->assertSame(33, $rates->convert(100, $third), 'Rounds down, deliberately.');
    }

    #[Test]
    public function setting_a_rate_supersedes_the_old_one_without_editing_it(): void
    {
        $rates = app(RateResolver::class);
        $before = $rates->require(RateResolver::DIAMOND_TO_INR);

        $rates->set(RateResolver::DIAMOND_TO_INR, 60, 1);

        $before->refresh();

        // The old row survives, closed rather than overwritten — history stays readable.
        $this->assertNotNull($before->effective_to);
        $this->assertSame(50, $before->rate_numerator);
        $this->assertSame(60, $rates->require(RateResolver::DIAMOND_TO_INR)->rate_numerator);
    }

    #[Test]
    public function a_withdrawal_keeps_the_rate_it_was_raised_at(): void
    {
        // A.7a — "historical requests are never re-priced".
        $user = $this->makeUserWithDiamonds(10_000);

        $withdrawal = app(WithdrawalService::class)->request($user, 10_000);

        $this->assertSame(500_000, $withdrawal->net_paise, '10,000 diamonds at 50 paise = ₹5,000.');

        // The rate doubles after the request is raised.
        app(RateResolver::class)->set(RateResolver::DIAMOND_TO_INR, 100, 1);

        app(WithdrawalService::class)->approve($withdrawal->fresh(), $this->superAdmin);

        $this->assertSame(
            500_000,
            $withdrawal->fresh()->net_paise,
            'Approving after a rate change must not re-price the request.',
        );
    }

    #[Test]
    public function a_rate_cannot_be_backdated_behind_a_scheduled_one(): void
    {
        $rates = app(RateResolver::class);

        $rates->set('test_future', 10, 1, from: now()->addWeek());

        $this->expectException(EconomyException::class);
        $rates->set('test_future', 20, 1, from: now());
    }

    // -------------------------------------------------------------------- A.7b

    #[Test]
    public function requesting_freezes_diamonds_without_moving_the_balance(): void
    {
        $user = $this->makeUserWithDiamonds(10_000);

        app(WithdrawalService::class)->request($user, 4_000);

        $wallet = Wallet::where('user_id', $user->id)->first();

        // docs/02 §15 rule 10 — freeze, then pay.
        $this->assertSame(10_000, $wallet->diamond_balance, 'The balance does not move on request.');
        $this->assertSame(4_000, $wallet->frozen_diamonds);
        $this->assertSame(6_000, $wallet->availableOf(Wallet::DIAMOND), 'Only the unfrozen part is spendable.');
    }

    #[Test]
    public function approving_converts_frozen_diamonds_into_a_ledger_entry(): void
    {
        $user = $this->makeUserWithDiamonds(10_000);
        $withdrawal = app(WithdrawalService::class)->request($user, 4_000);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/withdrawals/{$withdrawal->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Withdrawal::APPROVED);

        $wallet = Wallet::where('user_id', $user->id)->first();

        $this->assertSame(6_000, $wallet->diamond_balance, 'The diamonds have left for good.');
        $this->assertSame(0, $wallet->frozen_diamonds, 'Nothing stays frozen after payment.');
        $this->assertSame(200_000, $wallet->lifetime_withdrawn_paise);

        $row = DiamondTransaction::where('type', 'withdrawal_settled')->first();

        $this->assertNotNull($row, 'A balance change needs a ledger row beside it.');
        $this->assertSame(4_000, $row->amount);
        $this->assertSame(10_000, $row->balance_before);
        $this->assertSame(6_000, $row->balance_after);
    }

    #[Test]
    public function rejecting_returns_exactly_the_frozen_amount(): void
    {
        $user = $this->makeUserWithDiamonds(10_000);
        $withdrawal = app(WithdrawalService::class)->request($user, 4_000);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/withdrawals/{$withdrawal->id}/reject", ['reason' => 'KYC not verified.'])
            ->assertOk()
            ->assertJsonPath('data.diamonds_returned', 4000);

        $wallet = Wallet::where('user_id', $user->id)->first();

        $this->assertSame(10_000, $wallet->diamond_balance, 'The balance never moved, so nothing is credited back.');
        $this->assertSame(0, $wallet->frozen_diamonds);
        $this->assertSame(10_000, $wallet->availableOf(Wallet::DIAMOND), 'All of it is spendable again.');

        // No ledger row: nothing actually moved.
        $this->assertSame(1, DiamondTransaction::where('user_id', $user->id)->count());
    }

    #[Test]
    public function approve_and_reject_are_mutually_exclusive(): void
    {
        $user = $this->makeUserWithDiamonds(10_000);
        $withdrawal = app(WithdrawalService::class)->request($user, 4_000);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/withdrawals/{$withdrawal->id}/approve")->assertOk();

        // A.7b — the two paths cannot both run.
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/withdrawals/{$withdrawal->id}/reject", ['reason' => 'Changed my mind.'])
            ->assertStatus(400);

        $this->assertSame(Withdrawal::APPROVED, $withdrawal->fresh()->status);
        $this->assertSame(6_000, Wallet::where('user_id', $user->id)->value('diamond_balance'));
    }

    #[Test]
    public function a_high_value_payout_waits_for_a_super_admin(): void
    {
        // Threshold is ₹50,000 = 5,000,000 paise; 200,000 diamonds at 50p = ₹100,000.
        $user = $this->makeUserWithDiamonds(200_000);
        $withdrawal = app(WithdrawalService::class)->request($user, 200_000);

        $admin = $this->makeAdmin(Role::ADMIN);

        $this->actingAs($admin, 'sanctum-admin')
            ->postJson($this->base."/withdrawals/{$withdrawal->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Withdrawal::PENDING_SUPER)
            ->assertJsonPath('data.needs_super_admin', true);

        $wallet = Wallet::where('user_id', $user->id)->first();

        $this->assertSame(200_000, $wallet->diamond_balance, 'Nothing is paid until the second approval.');
        $this->assertSame(200_000, $wallet->frozen_diamonds, 'It stays frozen while it waits.');

        // A second Admin cannot clear it either — it needs a Super Admin specifically.
        $otherAdmin = $this->makeAdmin(Role::ADMIN, 'admin2@test.local');

        $this->actingAs($otherAdmin, 'sanctum-admin')
            ->postJson($this->base."/withdrawals/{$withdrawal->id}/approve")
            ->assertStatus(403);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/withdrawals/{$withdrawal->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Withdrawal::APPROVED);

        $withdrawal->refresh();

        $this->assertSame($admin->id, $withdrawal->reviewed_by, 'The first approver is remembered.');
        $this->assertSame($this->superAdmin->id, $withdrawal->second_approved_by);
        $this->assertSame(0, Wallet::where('user_id', $user->id)->value('frozen_diamonds'));
    }

    #[Test]
    public function a_small_payout_does_not_need_a_second_approval(): void
    {
        $user = $this->makeUserWithDiamonds(10_000);
        $withdrawal = app(WithdrawalService::class)->request($user, 2_000);   // ₹1,000

        $this->actingAs($this->makeAdmin(Role::ADMIN), 'sanctum-admin')
            ->postJson($this->base."/withdrawals/{$withdrawal->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Withdrawal::APPROVED);
    }

    #[Test]
    public function a_withdrawal_below_the_minimum_is_refused(): void
    {
        $user = $this->makeUserWithDiamonds(10_000);

        $this->expectException(EconomyException::class);
        app(WithdrawalService::class)->request($user, 10);   // minimum is 1000
    }

    #[Test]
    public function a_user_cannot_withdraw_frozen_diamonds_twice(): void
    {
        $user = $this->makeUserWithDiamonds(10_000);

        app(WithdrawalService::class)->request($user, 6_000);

        // 4,000 remain available, so a second 6,000 request must fail.
        $this->expectException(EconomyException::class);
        app(WithdrawalService::class)->request($user, 6_000);
    }

    #[Test]
    public function rejecting_requires_a_reason(): void
    {
        $user = $this->makeUserWithDiamonds(10_000);
        $withdrawal = app(WithdrawalService::class)->request($user, 2_000);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/withdrawals/{$withdrawal->id}/reject", [])
            ->assertStatus(422);
    }

    #[Test]
    public function approve_and_reject_are_separately_permissioned(): void
    {
        $user = $this->makeUserWithDiamonds(10_000);
        $withdrawal = app(WithdrawalService::class)->request($user, 2_000);

        $admin = $this->makeAdmin(Role::ADMIN);
        app(\App\Domain\Access\Services\MfaReauthGate::class)->markSatisfied($this->superAdmin);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$admin->id}/permissions/deny", ['permissions' => ['payouts.approve']])
            ->assertOk();

        $this->actingAs($admin->fresh(), 'sanctum-admin')
            ->postJson($this->base."/withdrawals/{$withdrawal->id}/approve")
            ->assertStatus(403);

        // Still allowed to refuse one.
        $this->actingAs($admin->fresh(), 'sanctum-admin')
            ->postJson($this->base."/withdrawals/{$withdrawal->id}/reject", ['reason' => 'Not eligible.'])
            ->assertOk();
    }

    // -------------------------------------------------------------------- A.7c

    #[Test]
    public function overlapping_commission_slabs_are_refused_naming_the_overlap(): void
    {
        // The seeder laid down 0–100k, 100k–1m, 1m+.
        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/economy/commission-slabs', [
                'applies_to' => 'platform', 'metric' => 'diamonds_earned',
                'min_value' => 50_000, 'max_value' => 200_000, 'percentage_bp' => 2800,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $overlapping = $response->json('error.details.overlapping');

        $this->assertNotEmpty($overlapping, 'The response must name the ranges it collides with.');
        $this->assertCount(2, $overlapping, 'It straddles two existing slabs.');
    }

    #[Test]
    public function a_non_overlapping_slab_is_accepted(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/economy/commission-slabs', [
                'applies_to' => 'agency', 'metric' => 'diamonds_earned',
                'min_value' => 0, 'max_value' => 50_000, 'percentage_bp' => 1000,
            ])
            ->assertStatus(201);

        // A different `applies_to` is a separate ladder, so it cannot collide.
        $this->assertSame(1, CommissionSlab::where('applies_to', 'agency')->count());
    }

    #[Test]
    public function commission_is_basis_points_and_applies_as_integer_arithmetic(): void
    {
        $slab = CommissionSlab::where('min_value', 0)->first();

        $this->assertSame(3000, $slab->percentage_bp);
        $this->assertSame(30.0, $slab->percent());
        // 30% of 12,345 = 3,703.5 → 3,703, rounded down, never a float.
        $this->assertSame(3_703, $slab->applyTo(12_345));
    }

    #[Test]
    public function closing_a_slab_keeps_it_for_history(): void
    {
        $slab = CommissionSlab::where('min_value', 0)->first();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->deleteJson($this->base."/economy/commission-slabs/{$slab->id}")
            ->assertOk();

        $this->assertDatabaseHas('commission_slabs', ['id' => $slab->id]);
        $this->assertNotNull($slab->fresh()->effective_to, 'Closed, not deleted.');
    }

    // -------------------------------------------------------------------- A.7d

    #[Test]
    public function reconciliation_passes_when_every_wallet_matches_its_ledger(): void
    {
        $this->makeUserWithDiamonds(5_000);
        $this->makeUserWithDiamonds(1_200);

        $report = app(Reconciler::class)->run();

        $this->assertTrue($report['ok'], 'A clean set of books must reconcile: '.json_encode($report));
        $this->assertSame([], $report['currencies']['diamond']['mismatches']);
    }

    #[Test]
    public function reconciliation_names_the_user_and_the_delta_on_a_mismatch(): void
    {
        $user = $this->makeUserWithDiamonds(5_000);

        // Move a balance WITHOUT a ledger row — precisely what the money rules forbid, and
        // exactly the drift reconciliation exists to find.
        DB::table('wallets')->where('user_id', $user->id)->update(['diamond_balance' => 7_500]);

        $report = app(Reconciler::class)->run();

        $this->assertFalse($report['ok']);

        $mismatch = collect($report['currencies']['diamond']['mismatches'])->firstWhere('user_id', $user->id);

        $this->assertNotNull($mismatch, 'The report must name the user.');
        $this->assertSame(7_500, $mismatch['wallet_balance']);
        $this->assertSame(5_000, $mismatch['ledger_total']);
        $this->assertSame(2_500, $mismatch['delta'], 'And the exact delta.');
    }

    #[Test]
    public function the_reconciliation_endpoint_reports_cleanly(): void
    {
        $this->makeUserWithDiamonds(1_000);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/economy/reconciliation/run')
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $this->assertTrue(AuditLog::where('action', 'economy.reconcile')->exists());
    }

    // ------------------------------------------------------------------ ledger

    #[Test]
    public function the_unified_ledger_filters_by_currency_and_user(): void
    {
        $user = $this->makeUserWithDiamonds(3_000);
        app(WalletService::class)->adjust($user, 'coin', 'credit', 500, 'coins too', $this->superAdmin);

        $diamonds = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson($this->base."/economy/ledger?currency=diamond&user_id={$user->id}")->assertOk();

        $coins = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson($this->base."/economy/ledger?currency=coin&user_id={$user->id}")->assertOk();

        $this->assertCount(1, $diamonds->json('data'));
        $this->assertCount(1, $coins->json('data'));
        $this->assertSame(3_000, $diamonds->json('data.0.amount'));
        $this->assertSame(500, $coins->json('data.0.amount'));
    }

    // ------------------------------------------------------------ route gating

    #[Test]
    public function a_moderator_cannot_reach_the_economy(): void
    {
        $moderator = $this->makeAdmin(Role::MODERATOR);

        foreach (['/economy/rates', '/economy/ledger', '/withdrawals', '/economy/reconciliation'] as $path) {
            $this->actingAs($moderator, 'sanctum-admin')
                ->getJson($this->base.$path)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'PERMISSION_DENIED');
        }
    }

    #[Test]
    public function an_admin_cannot_change_conversion_rates(): void
    {
        // economy.rates_manage is one of the three keys the admin baseline excludes.
        $admin = $this->makeAdmin(Role::ADMIN);

        $this->actingAs($admin, 'sanctum-admin')
            ->patchJson($this->base.'/economy/rates', [
                'key' => RateResolver::DIAMOND_TO_INR, 'rate_numerator' => 999, 'rate_denominator' => 1,
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function the_withdrawal_policy_thresholds_are_configurable(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson($this->base.'/withdrawal-settings', [
                'minimum_diamonds' => 500, 'super_approval_paise' => 10_000_000,
            ])
            ->assertOk()
            ->assertJsonPath('data.minimum_diamonds', 500);

        $this->assertSame(500, app(SettingsRepository::class)->int('economy.withdrawal_minimum_diamonds'));
    }
}
