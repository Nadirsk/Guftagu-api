<?php

namespace Tests\Feature\Admin;

use App\Domain\Settings\SettingsRepository;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Mail\AdminOtpMail;
use App\Models\AdminLoginAttempt;
use App\Models\AdminMfaChallenge;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GFT-012 — "Auth test suite: lockout, MFA bypass attempts, expired OTP, token replay."
 * Acceptance criteria A.1a–A.1d from docs/04.
 */
class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class]);

        // Throttling is not what these tests are about, and 5/min would mask the lockout.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    protected function makeAdmin(string $roleKey = Role::ADMIN, array $attributes = []): AdminUser
    {
        return AdminUser::create(array_merge([
            'name'     => ucfirst($roleKey),
            'email'    => $roleKey.'@test.local',
            'password' => 'Password12345',
            'role_id'  => Role::where('key', $roleKey)->value('id'),
            'status'   => 'active',
        ], $attributes));
    }

    /** Pull the OTP out of the mailable that was "sent". */
    protected function lastOtp(): string
    {
        $mailable = Mail::sent(AdminOtpMail::class)->last();

        $this->assertNotNull($mailable, 'No OTP email was sent.');

        return $mailable->otp;
    }

    // ------------------------------------------------------------------- A.1a

    #[Test]
    public function login_with_mfa_returns_a_challenge_and_no_token(): void
    {
        Mail::fake();

        $admin = $this->makeAdmin(Role::ADMIN, ['mfa_enabled' => true]);

        $response = $this->postJson($this->base.'/auth/login', [
            'email'    => $admin->email,
            'password' => 'Password12345',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.mfa_required', true)
            ->assertJsonStructure(['data' => ['challenge_id', 'expires_at', 'sent_to']]);

        // The whole point of A.1a: no access token exists yet.
        $response->assertJsonMissingPath('data.token');
        $this->assertSame(0, $admin->tokens()->count());
    }

    #[Test]
    public function verifying_the_emailed_otp_issues_a_token(): void
    {
        Mail::fake();

        $admin = $this->makeAdmin(Role::ADMIN, ['mfa_enabled' => true]);

        $challengeId = $this->postJson($this->base.'/auth/login', [
            'email' => $admin->email, 'password' => 'Password12345',
        ])->json('data.challenge_id');

        $this->postJson($this->base.'/auth/mfa/verify', [
            'challenge_id' => $challengeId,
            'otp'          => $this->lastOtp(),
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'expires_at', 'admin']]);

        $this->assertSame(1, $admin->tokens()->count());
    }

    #[Test]
    public function an_otp_cannot_be_replayed(): void
    {
        Mail::fake();

        $admin = $this->makeAdmin(Role::ADMIN, ['mfa_enabled' => true]);

        $challengeId = $this->postJson($this->base.'/auth/login', [
            'email' => $admin->email, 'password' => 'Password12345',
        ])->json('data.challenge_id');

        $otp = $this->lastOtp();

        $this->postJson($this->base.'/auth/mfa/verify', ['challenge_id' => $challengeId, 'otp' => $otp])->assertOk();

        $this->postJson($this->base.'/auth/mfa/verify', ['challenge_id' => $challengeId, 'otp' => $otp])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'BAD_REQUEST');
    }

    #[Test]
    public function an_expired_otp_is_rejected(): void
    {
        Mail::fake();

        $admin = $this->makeAdmin(Role::ADMIN, ['mfa_enabled' => true]);

        $challengeId = $this->postJson($this->base.'/auth/login', [
            'email' => $admin->email, 'password' => 'Password12345',
        ])->json('data.challenge_id');

        $otp = $this->lastOtp();

        AdminMfaChallenge::where('id', $challengeId)->update(['expires_at' => now()->subMinute()]);

        $this->postJson($this->base.'/auth/mfa/verify', ['challenge_id' => $challengeId, 'otp' => $otp])
            ->assertStatus(400);
    }

    #[Test]
    public function a_wrong_otp_is_counted_and_eventually_burns_the_challenge(): void
    {
        Mail::fake();

        $admin = $this->makeAdmin(Role::ADMIN, ['mfa_enabled' => true]);

        $challengeId = $this->postJson($this->base.'/auth/login', [
            'email' => $admin->email, 'password' => 'Password12345',
        ])->json('data.challenge_id');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->base.'/auth/mfa/verify', [
                'challenge_id' => $challengeId, 'otp' => '000000',
            ])->assertStatus(401);
        }

        // The 6th attempt finds the challenge spent, even with the right code.
        $this->postJson($this->base.'/auth/mfa/verify', [
            'challenge_id' => $challengeId, 'otp' => $this->lastOtp(),
        ])->assertStatus(429);
    }

    #[Test]
    public function five_failures_lock_the_account_and_the_attempt_is_audited(): void
    {
        $admin = $this->makeAdmin(Role::ADMIN);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->base.'/auth/login', [
                'email' => $admin->email, 'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        // Even the CORRECT password is refused while locked.
        $response = $this->postJson($this->base.'/auth/login', [
            'email' => $admin->email, 'password' => 'Password12345',
        ]);

        $response->assertStatus(423)
            ->assertJsonPath('error.code', 'ACCOUNT_LOCKED')
            ->assertJsonStructure(['error' => ['details' => ['locked_until']]]);

        $this->assertTrue(
            AuditLog::where('action', 'admin.login_locked')->exists(),
            'The lockout was not written to audit_logs.'
        );
    }

    #[Test]
    public function a_successful_login_clears_the_failure_streak(): void
    {
        $admin = $this->makeAdmin(Role::ADMIN);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson($this->base.'/auth/login', ['email' => $admin->email, 'password' => 'nope'])
                ->assertStatus(401);
        }

        $this->postJson($this->base.'/auth/login', ['email' => $admin->email, 'password' => 'Password12345'])
            ->assertOk();

        // Four more failures must not tip it over, because the streak restarted.
        for ($i = 0; $i < 4; $i++) {
            $this->postJson($this->base.'/auth/login', ['email' => $admin->email, 'password' => 'nope'])
                ->assertStatus(401);
        }

        $this->postJson($this->base.'/auth/login', ['email' => $admin->email, 'password' => 'Password12345'])
            ->assertOk();
    }

    #[Test]
    public function login_does_not_reveal_whether_an_email_exists(): void
    {
        $admin = $this->makeAdmin(Role::ADMIN);

        $unknown = $this->postJson($this->base.'/auth/login', ['email' => 'nobody@test.local', 'password' => 'x']);
        $known   = $this->postJson($this->base.'/auth/login', ['email' => $admin->email, 'password' => 'wrong']);

        $this->assertSame($unknown->status(), $known->status());
        $this->assertSame($unknown->json('message'), $known->json('message'));
        $this->assertSame($unknown->json('error.code'), $known->json('error.code'));
    }

    #[Test]
    public function a_suspended_admin_cannot_log_in(): void
    {
        $admin = $this->makeAdmin(Role::ADMIN, ['status' => 'suspended']);

        $this->postJson($this->base.'/auth/login', ['email' => $admin->email, 'password' => 'Password12345'])
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------- A.1b

    #[Test]
    public function changing_password_without_the_current_one_is_422(): void
    {
        $admin = $this->makeAdmin(Role::ADMIN);

        $this->actingAs($admin, 'sanctum-admin')
            ->postJson($this->base.'/auth/password', [
                'password'              => 'BrandNewPass123',
                'password_confirmation' => 'BrandNewPass123',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function changing_password_revokes_every_other_session(): void
    {
        $admin = $this->makeAdmin(Role::ADMIN);

        $keep  = $admin->createToken('deviceA', ['admin'], now()->addDay());
        $other = $admin->createToken('deviceB', ['admin'], now()->addDay());

        EnforceIdleTimeout::touch($keep->accessToken->id, 60);
        EnforceIdleTimeout::touch($other->accessToken->id, 60);

        $this->withHeader('Authorization', 'Bearer '.$keep->plainTextToken)
            ->postJson($this->base.'/auth/password', [
                'current_password'      => 'Password12345',
                'password'              => 'BrandNewPass123',
                'password_confirmation' => 'BrandNewPass123',
            ])
            ->assertOk();

        $this->assertSame(1, $admin->tokens()->count(), 'Other device tokens were not revoked.');
        $this->assertTrue(Hash::check('BrandNewPass123', $admin->fresh()->password));
    }

    // ------------------------------------------------------------------- A.1c

    #[Test]
    public function an_idle_session_beyond_the_timeout_returns_token_expired(): void
    {
        $admin = $this->makeAdmin(Role::ADMIN);

        app(SettingsRepository::class)->set('security.session_timeout_minutes', 30);

        $token = $admin->createToken('idle', ['admin'], now()->addDay());
        EnforceIdleTimeout::touch($token->accessToken->id, 30);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson($this->base.'/auth/me')->assertOk();

        // 31 minutes idle: the marker's TTL has lapsed.
        EnforceIdleTimeout::forget($token->accessToken->id);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson($this->base.'/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'TOKEN_EXPIRED');

        $this->assertSame(0, $admin->tokens()->count(), 'The expired token should be deleted.');
    }

    #[Test]
    public function a_per_account_timeout_overrides_the_platform_default(): void
    {
        app(SettingsRepository::class)->set('security.session_timeout_minutes', 60);

        $admin = $this->makeAdmin(Role::ADMIN, ['session_timeout_minutes' => 15]);

        $this->assertSame(15, $admin->sessionTimeoutMinutes());
    }

    // ------------------------------------------------------------------- A.1d

    #[Test]
    public function disabling_2fa_for_a_role_stops_the_challenge_and_is_audited(): void
    {
        Mail::fake();

        $superAdmin = $this->makeAdmin(Role::SUPER_ADMIN, ['email' => 'sa@test.local']);
        $moderator  = $this->makeAdmin(Role::MODERATOR, ['email' => 'mod@test.local']);

        app(SettingsRepository::class)->set('security.mfa_required.moderator', true);

        $this->postJson($this->base.'/auth/login', ['email' => $moderator->email, 'password' => 'Password12345'])
            ->assertJsonPath('data.mfa_required', true);

        $this->actingAs($superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/auth/mfa/toggle/moderator', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.mfa_required', false);

        $this->postJson($this->base.'/auth/login', ['email' => $moderator->email, 'password' => 'Password12345'])
            ->assertOk()
            ->assertJsonPath('data.mfa_required', false)
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertTrue(
            AuditLog::where('action', 'settings.mfa_toggle')->where('admin_user_id', $superAdmin->id)->exists(),
            'The 2FA policy change was not audited with an actor.'
        );
    }

    #[Test]
    public function a_manager_cannot_change_the_2fa_policy(): void
    {
        $manager = $this->makeAdmin(Role::MANAGER, ['email' => 'mgr@test.local']);

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson($this->base.'/auth/mfa/toggle/moderator', ['enabled' => false])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    // ------------------------------------------------------------- me / logout

    #[Test]
    public function me_returns_the_effective_permission_list(): void
    {
        $moderator = $this->makeAdmin(Role::MODERATOR, ['email' => 'mod2@test.local']);

        $response = $this->actingAs($moderator, 'sanctum-admin')->getJson($this->base.'/auth/me')->assertOk();

        $this->assertEqualsCanonicalizing(RoleSeeder::MODERATOR_BASELINE, $response->json('data.permissions'));
    }

    #[Test]
    public function a_super_admin_sees_every_permission_key(): void
    {
        $superAdmin = $this->makeAdmin(Role::SUPER_ADMIN, ['email' => 'sa2@test.local']);

        $response = $this->actingAs($superAdmin, 'sanctum-admin')->getJson($this->base.'/auth/me')->assertOk();

        $this->assertCount(count(PermissionSeeder::keys()), $response->json('data.permissions'));
    }

    // ------------------------------------------------- local-only fixed OTP

    #[Test]
    public function a_static_otp_is_used_in_local(): void
    {
        Mail::fake();

        $this->app['env'] = 'local';
        config(['guftagu.admin_mfa.static_otp' => '123456']);

        $admin = $this->makeAdmin(Role::ADMIN, ['mfa_enabled' => true]);

        $challengeId = $this->postJson($this->base.'/auth/login', [
            'email' => $admin->email, 'password' => 'Password12345',
        ])->json('data.challenge_id');

        $this->assertSame('123456', $this->lastOtp());

        // And it genuinely verifies — a fixed code that did not work would be worse than none.
        $this->postJson($this->base.'/auth/mfa/verify', [
            'challenge_id' => $challengeId,
            'otp'          => '123456',
        ])->assertOk();
    }

    #[Test]
    public function a_static_otp_is_ignored_outside_local(): void
    {
        Mail::fake();

        // Configured exactly as a dev machine would be, but the environment is not local.
        config(['guftagu.admin_mfa.static_otp' => '123456']);
        $this->assertFalse($this->app->environment('local'), 'This test is meaningless in local.');

        $admin = $this->makeAdmin(Role::ADMIN, ['mfa_enabled' => true]);
        $codes = [];

        // Several draws: one random code could coincidentally be 123456, five cannot.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->base.'/auth/login', [
                'email' => $admin->email, 'password' => 'Password12345',
            ])->assertOk();

            $codes[] = $this->lastOtp();
        }

        $this->assertNotSame(['123456', '123456', '123456', '123456', '123456'], $codes);
        $this->assertGreaterThan(1, count(array_unique($codes)), 'Codes outside local must be random.');
    }

    #[Test]
    public function a_malformed_static_otp_falls_back_to_a_random_code(): void
    {
        Mail::fake();

        $this->app['env'] = 'local';
        config(['guftagu.admin_mfa.static_otp' => 'letmein']);   // not six digits

        $admin = $this->makeAdmin(Role::ADMIN, ['mfa_enabled' => true]);

        $this->postJson($this->base.'/auth/login', [
            'email' => $admin->email, 'password' => 'Password12345',
        ])->assertOk();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->lastOtp());
    }

    #[Test]
    public function unauthenticated_requests_use_the_standard_envelope(): void
    {
        $this->getJson($this->base.'/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonStructure(['meta' => ['request_id', 'timestamp']]);
    }
}
