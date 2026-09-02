<?php

namespace Tests\Feature\Admin;

use App\Domain\Access\Services\MfaReauthGate;
use App\Domain\Wallet\WalletService;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\CoinTransaction;
use App\Models\Role;
use App\Models\User;
use App\Models\UserKyc;
use App\Models\UserProfile;
use App\Models\UserSanction;
use App\Models\Wallet;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Epic A.3 acceptance criteria, plus GFT-035 (wallet adjustment: permission gate, ledger
 * correctness, audit trail) and the docs/02 §15 money-integrity rules the adjustment
 * endpoint has to honour.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected AdminUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->superAdmin = $this->makeAdmin(Role::SUPER_ADMIN);

        // Several tests shape a permission set by denying a `high` risk key
        // (users.view_pii, wallet.manual_debit), and GFT-122 requires fresh MFA for that.
        // Satisfying it here keeps those tests about A.3 rather than re-testing A.11.
        app(MfaReauthGate::class)->markSatisfied($this->superAdmin);
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

    protected function makeUser(array $attributes = [], string $name = 'Test Person'): User
    {
        static $seq = 0;
        $seq++;

        $user = User::create(array_merge([
            'guftagu_id' => 'GF'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
            'phone'      => '+9198200'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'email'      => "person{$seq}@example.com",
            'status'     => User::STATUS_ACTIVE,
            'agora_uid'  => 900000 + $seq,
        ], $attributes));

        UserProfile::create(['user_id' => $user->id, 'display_name' => $name]);

        return $user;
    }

    // -------------------------------------------------------------------- A.3a

    #[Test]
    public function a_user_is_found_by_full_phone_even_though_the_column_is_encrypted(): void
    {
        $user = $this->makeUser(['phone' => '+919876543210'], 'Findable Person');
        $this->makeUser([], 'Someone Else');

        // Both the bare number and the E.164 form must resolve to the same person.
        foreach (['+919876543210', '9876543210'] as $term) {
            $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
                ->getJson($this->base.'/users?q='.urlencode($term))
                ->assertOk();

            $ids = collect($response->json('data'))->pluck('id')->all();

            $this->assertSame([$user->id], $ids, "Searching '{$term}' did not find the user.");
        }
    }

    #[Test]
    public function the_phone_is_masked_in_every_list_and_detail_response(): void
    {
        $user = $this->makeUser(['phone' => '+919876543210']);

        $row = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson($this->base.'/users')->json('data.0');

        // docs/01 §6 spells the shape out exactly: dialling code readable, subscriber
        // number hidden but for its first and last two digits.
        $this->assertSame('+91 98••••••10', $row['phone_masked']);
        $this->assertStringNotContainsString('9876543210', json_encode($row));

        $detail = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson($this->base.'/users/'.$user->id)->assertOk();

        $this->assertStringNotContainsString('9876543210', json_encode($detail->json('data.user')));
    }

    #[Test]
    public function unmasking_requires_view_pii_and_is_audited(): void
    {
        $user = $this->makeUser(['phone' => '+919876543210']);

        // An Admin holds users.view but the baseline also includes view_pii, so deny it
        // explicitly to model someone who genuinely lacks the key.
        $admin = $this->makeAdmin(Role::ADMIN);
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$admin->id}/permissions/deny", [
                'permissions' => ['users.view_pii'],
            ])->assertOk();

        $this->actingAs($admin->fresh(), 'sanctum-admin')
            ->getJson($this->base."/users/{$user->id}/pii")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');

        $this->assertFalse(
            AuditLog::where('action', 'user.pii_viewed')->exists(),
            'A refused unmask must not be recorded as a view.'
        );

        // And with the permission: the real value, plus a record that it was seen.
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson($this->base."/users/{$user->id}/pii")
            ->assertOk()
            ->assertJsonPath('data.phone', '+919876543210');

        $this->assertTrue(
            AuditLog::where('action', 'user.pii_viewed')
                ->where('admin_user_id', $this->superAdmin->id)
                ->where('entity_id', (string) $user->id)
                ->exists(),
            'Viewing PII must write an audit row naming the viewer and the subject.'
        );
    }

    // -------------------------------------------------------------------- A.3b

    #[Test]
    public function approving_kyc_marks_the_user_verified(): void
    {
        $user = $this->makeUser();
        UserKyc::create([
            'user_id'    => $user->id,
            'full_name'  => 'Test Person',
            'doc_type'   => 'aadhaar',
            'doc_number' => '987654321012',
            'status'     => UserKyc::PENDING,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/kyc/verify", ['decision' => 'verified'])
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        $this->assertSame(UserKyc::VERIFIED, $user->fresh()->kyc->status);
        $this->assertTrue(AuditLog::where('action', 'user.kyc_review')->exists());
    }

    #[Test]
    public function rejecting_kyc_demands_a_reason_and_a_decision_is_final(): void
    {
        $user = $this->makeUser();
        UserKyc::create([
            'user_id' => $user->id, 'full_name' => 'Test Person',
            'doc_type' => 'aadhaar', 'doc_number' => '987654321012', 'status' => UserKyc::PENDING,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/kyc/verify", ['decision' => 'rejected'])
            ->assertStatus(422);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/kyc/verify", [
                'decision' => 'rejected', 'reason' => 'Document unreadable.',
            ])->assertOk();

        // Re-reviewing a decided submission is refused rather than silently overwriting it.
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/kyc/verify", ['decision' => 'verified'])
            ->assertStatus(400);
    }

    // -------------------------------------------------------------------- A.3c

    #[Test]
    public function banning_records_a_reason_ends_sessions_and_blocks_the_account(): void
    {
        $user = $this->makeUser();
        $user->createToken('phone', ['*'], now()->addDay());
        $this->assertSame(1, $user->tokens()->count());

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/ban", ['reason' => 'Harassment in voice rooms.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'banned');

        $user->refresh();

        $this->assertSame(User::STATUS_BANNED, $user->status);
        $this->assertSame(0, $user->tokens()->count(), 'A ban must end live sessions immediately.');

        $sanction = UserSanction::where('user_id', $user->id)->first();
        $this->assertNotNull($sanction);
        $this->assertSame('Harassment in voice rooms.', $sanction->reason);
        $this->assertSame($this->superAdmin->id, $sanction->issued_by);
    }

    #[Test]
    public function a_ban_without_a_reason_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/ban", [])
            ->assertStatus(422);

        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
    }

    #[Test]
    public function unbanning_revokes_the_active_sanction(): void
    {
        $user = $this->makeUser();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/ban", ['reason' => 'Spam.'])->assertOk();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/unban", ['reason' => 'Appeal upheld.'])
            ->assertOk();

        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
        $this->assertSame(0, UserSanction::where('user_id', $user->id)->active()->count());
    }

    #[Test]
    public function an_expired_suspension_stops_counting_as_active(): void
    {
        $user = $this->makeUser();

        $sanction = UserSanction::create([
            'user_id'    => $user->id,
            'type'       => UserSanction::TEMP_BAN,
            'reason'     => 'Cooling off.',
            'starts_at'  => now()->subDays(5),
            'expires_at' => now()->subDay(),
            'is_active'  => true,
        ]);

        $this->assertTrue($sanction->is_active);
        $this->assertSame(0, UserSanction::where('user_id', $user->id)->active()->count());
    }

    // -------------------------------------------------------------------- A.3d

    #[Test]
    public function a_manual_credit_moves_the_balance_and_writes_a_correct_ledger_row(): void
    {
        $user = $this->makeUser();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/wallet/credit", [
                'currency' => 'coin', 'amount' => 1000, 'note' => 'Goodwill for the outage.',
            ])
            ->assertOk()
            ->assertJsonPath('data.balance_before', 0)
            ->assertJsonPath('data.balance_after', 1000);

        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertSame(1000, $wallet->coin_balance);

        $row = CoinTransaction::where('user_id', $user->id)->first();

        $this->assertSame('admin_credit', $row->type);
        $this->assertSame('credit', $row->direction);
        $this->assertSame(1000, $row->amount);
        $this->assertSame(0, $row->balance_before);
        $this->assertSame(1000, $row->balance_after, 'balance_after must equal the new balance.');
        $this->assertSame($this->superAdmin->id, $row->performed_by);
        $this->assertSame('Goodwill for the outage.', $row->note);

        $this->assertTrue(
            AuditLog::where('action', 'wallet.manual_credit')->where('entity_id', (string) $user->id)->exists(),
            'A manual adjustment must also write an audit entry.'
        );
    }

    #[Test]
    public function a_manual_adjustment_without_a_note_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/wallet/credit", [
                'currency' => 'coin', 'amount' => 500,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame(0, CoinTransaction::count(), 'Nothing may be written without a note.');
    }

    #[Test]
    public function a_debit_larger_than_the_balance_is_refused(): void
    {
        $user = $this->makeUser();
        app(WalletService::class)->adjust($user, 'coin', 'credit', 100, 'seed', $this->superAdmin);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/wallet/debit", [
                'currency' => 'coin', 'amount' => 500, 'note' => 'Clawback.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INSUFFICIENT_BALANCE');

        $this->assertSame(100, Wallet::where('user_id', $user->id)->value('coin_balance'));
    }

    #[Test]
    public function fractional_amounts_are_rejected(): void
    {
        $user = $this->makeUser();

        // docs/02 §15 rule 1 — integers only. `numeric` would have let this through.
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/wallet/credit", [
                'currency' => 'coin', 'amount' => 10.5, 'note' => 'Half a coin.',
            ])
            ->assertStatus(422);

        $this->assertSame(0, CoinTransaction::count());
    }

    #[Test]
    public function the_ledger_chain_stays_consistent_across_many_adjustments(): void
    {
        $user = $this->makeUser();
        $service = app(WalletService::class);

        $service->adjust($user, 'coin', 'credit', 1000, 'one', $this->superAdmin);
        $service->adjust($user, 'coin', 'credit', 250, 'two', $this->superAdmin);
        $service->adjust($user, 'coin', 'debit', 400, 'three', $this->superAdmin);
        $service->adjust($user, 'coin', 'credit', 75, 'four', $this->superAdmin);

        $integrity = $service->verifyIntegrity($user, 'coin');

        $this->assertTrue($integrity['ok'], 'Ledger chain broke: '.json_encode($integrity['breaks']));
        $this->assertSame(4, $integrity['checked']);
        $this->assertSame(925, $integrity['wallet_balance']);   // 1000 + 250 - 400 + 75
        $this->assertSame(925, $integrity['ledger_balance']);
    }

    #[Test]
    public function a_replayed_adjustment_does_not_move_money_twice(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 2; $i++) {
            $this->withHeader('X-Idempotency-Key', 'demo-key-1')
                ->actingAs($this->superAdmin, 'sanctum-admin')
                ->postJson($this->base."/users/{$user->id}/wallet/credit", [
                    'currency' => 'coin', 'amount' => 300, 'note' => 'Refund.',
                ])
                ->assertOk();
        }

        $this->assertSame(1, CoinTransaction::count(), 'A replay must return the original row.');
        $this->assertSame(300, Wallet::where('user_id', $user->id)->value('coin_balance'));
    }

    #[Test]
    public function ledger_rows_cannot_be_edited_or_deleted(): void
    {
        $user = $this->makeUser();
        app(WalletService::class)->adjust($user, 'coin', 'credit', 100, 'seed', $this->superAdmin);

        $row = CoinTransaction::first();

        // §15 rule 3 — a mistake is corrected with a compensating entry, never by editing.
        $this->expectException(\LogicException::class);
        $row->update(['amount' => 999999]);
    }

    #[Test]
    public function credit_and_debit_are_separately_permissioned(): void
    {
        $user = $this->makeUser();
        $admin = $this->makeAdmin(Role::ADMIN);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$admin->id}/permissions/deny", [
                'permissions' => ['wallet.manual_debit'],
            ])->assertOk();

        // Still allowed to give ...
        $this->actingAs($admin->fresh(), 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/wallet/credit", [
                'currency' => 'coin', 'amount' => 100, 'note' => 'Allowed.',
            ])->assertOk();

        // ... but not to take away.
        $this->actingAs($admin->fresh(), 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/wallet/debit", [
                'currency' => 'coin', 'amount' => 50, 'note' => 'Refused.',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    #[Test]
    public function coins_and_diamonds_never_touch_each_other(): void
    {
        $user = $this->makeUser();
        $service = app(WalletService::class);

        $service->adjust($user, 'coin', 'credit', 500, 'coins', $this->superAdmin);
        $service->adjust($user, 'diamond', 'credit', 90, 'diamonds', $this->superAdmin);

        $wallet = Wallet::where('user_id', $user->id)->first();

        $this->assertSame(500, $wallet->coin_balance);
        $this->assertSame(90, $wallet->diamond_balance);
        $this->assertSame(1, CoinTransaction::where('user_id', $user->id)->count());
        $this->assertSame(1, \App\Models\DiamondTransaction::where('user_id', $user->id)->count());
    }

    // ------------------------------------------------------------ route gating

    #[Test]
    public function a_moderator_cannot_reach_any_user_management_route(): void
    {
        $moderator = $this->makeAdmin(Role::MODERATOR);
        $user = $this->makeUser();

        $routes = [
            ['getJson', "/users"],
            ['getJson', "/users/{$user->id}"],
            ['getJson', "/users/{$user->id}/pii"],
            ['getJson', "/users/{$user->id}/wallet"],
            ['getJson', "/users/{$user->id}/transactions"],
        ];

        foreach ($routes as [$method, $path]) {
            $this->actingAs($moderator, 'sanctum-admin')
                ->{$method}($this->base.$path)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'PERMISSION_DENIED');
        }
    }
}
