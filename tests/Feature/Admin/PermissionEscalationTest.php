<?php

namespace Tests\Feature\Admin;

use App\Domain\Access\Services\MfaReauthGate;
use App\Domain\Access\Services\PermissionResolver;
use App\Domain\Access\Services\ScopeGate;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\PermissionGrantLog;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GFT-128 — the escalation test suite. Every acceptance criterion in docs/04 A.11,
 * exercised through the HTTP API so the "UI hiding the option does not satisfy this"
 * requirement is actually met.
 */
class PermissionEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected AdminUser $superAdmin;

    protected AdminUser $admin;

    protected AdminUser $manager;

    protected AdminUser $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->superAdmin = $this->makeAdmin(Role::SUPER_ADMIN);
        $this->admin      = $this->makeAdmin(Role::ADMIN);
        $this->manager    = $this->makeAdmin(Role::MANAGER);
        $this->moderator  = $this->makeAdmin(Role::MODERATOR);

        // High-risk grants need fresh MFA (GFT-122); satisfy it except where under test.
        app(MfaReauthGate::class)->markSatisfied($this->superAdmin);
        app(MfaReauthGate::class)->markSatisfied($this->admin);
    }

    protected function makeAdmin(string $roleKey): AdminUser
    {
        return AdminUser::create([
            'name'     => ucfirst($roleKey),
            'email'    => $roleKey.'@test.local',
            'password' => 'Password12345',
            'role_id'  => Role::where('key', $roleKey)->value('id'),
            'status'   => 'active',
        ]);
    }

    protected function resolver(): PermissionResolver
    {
        return app(PermissionResolver::class);
    }

    // ------------------------------------------------------- the superset guard

    #[Test]
    public function an_admin_cannot_grant_a_permission_they_do_not_hold(): void
    {
        // Strip payouts.approve from this Admin so they genuinely lack it.
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$this->admin->id}/permissions/deny", [
                'permissions' => ['payouts.approve'],
            ])->assertOk();

        $this->assertFalse($this->resolver()->has($this->admin->fresh(), 'payouts.approve'));

        $response = $this->actingAs($this->admin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$this->moderator->id}/permissions", [
                'permissions' => ['payouts.approve'],
                'reason'      => 'escalation attempt',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_ESCALATION_DENIED')
            ->assertJsonPath('error.details.ungranted', ['payouts.approve']);

        // "nothing is persisted"
        $this->assertFalse($this->resolver()->has($this->moderator->fresh(), 'payouts.approve'));
        $this->assertDatabaseMissing('admin_user_permission', [
            'admin_user_id' => $this->moderator->id,
            'permission_id' => Permission::where('key', 'payouts.approve')->value('id'),
        ]);

        // "and the attempt is logged"
        $this->assertTrue(
            AuditLog::where('action', 'permission.grant_refused')
                ->where('admin_user_id', $this->admin->id)->exists(),
            'A refused escalation must be audited.'
        );
    }

    #[Test]
    public function a_partial_escalation_is_refused_wholesale(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$this->admin->id}/permissions/deny", [
                'permissions' => ['payouts.approve'],
            ])->assertOk();

        // One key the Admin holds, one it does not — the whole call must fail.
        $this->actingAs($this->admin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$this->moderator->id}/permissions", [
                'permissions' => ['moderation.mute_user', 'payouts.approve'],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.details.ungranted', ['payouts.approve']);

        $this->assertFalse($this->resolver()->has($this->moderator->fresh(), 'moderation.mute_user'));
    }

    #[Test]
    public function a_super_admin_may_grant_anything(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$this->moderator->id}/permissions", [
                'permissions' => ['payouts.approve'],
            ])->assertOk();

        $this->assertTrue($this->resolver()->has($this->moderator->fresh(), 'payouts.approve'));
    }

    // ------------------------------------------------------------ target guard

    #[Test]
    public function a_manager_holding_the_permission_still_cannot_delegate(): void
    {
        // Reach the delegation guard: without the permission the route gate refuses first.
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$this->manager->id}/permissions", [
                'permissions' => ['access.permission_grant'],
            ])->assertOk();

        $this->actingAs($this->manager->fresh(), 'sanctum-admin')
            ->postJson($this->base."/admins/{$this->moderator->id}/permissions", [
                'permissions' => ['reports.view'],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'DELEGATION_TARGET_DENIED');
    }

    #[Test]
    public function a_manager_without_the_permission_is_refused_at_the_route(): void
    {
        $this->actingAs($this->manager, 'sanctum-admin')
            ->postJson($this->base."/admins/{$this->moderator->id}/permissions", [
                'permissions' => ['reports.view'],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    #[Test]
    public function an_admin_cannot_grant_to_another_admin_or_a_super_admin(): void
    {
        $peer = AdminUser::create([
            'name' => 'Peer', 'email' => 'peer@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::ADMIN)->value('id'), 'status' => 'active',
        ]);

        foreach ([$peer->id, $this->superAdmin->id] as $targetId) {
            $this->actingAs($this->admin, 'sanctum-admin')
                ->postJson($this->base."/admins/{$targetId}/permissions", [
                    'permissions' => ['reports.view'],
                ])
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'DELEGATION_TARGET_DENIED');
        }
    }

    #[Test]
    public function nobody_can_grant_to_themselves_not_even_a_super_admin(): void
    {
        foreach ([$this->superAdmin, $this->admin] as $actor) {
            $this->actingAs($actor, 'sanctum-admin')
                ->postJson($this->base."/admins/{$actor->id}/permissions", [
                    'permissions' => ['reports.view'],
                ])
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'SELF_GRANT_DENIED');
        }
    }

    // -------------------------------------------------------------- MFA re-entry

    #[Test]
    public function granting_a_high_risk_permission_requires_fresh_mfa(): void
    {
        app(MfaReauthGate::class)->clear($this->superAdmin);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$this->moderator->id}/permissions", [
                'permissions' => ['moderation.ban_permanent'],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'MFA_REQUIRED')
            ->assertJsonPath('error.details.high_risk', ['moderation.ban_permanent']);

        $this->assertFalse($this->resolver()->has($this->moderator->fresh(), 'moderation.ban_permanent'));
    }

    #[Test]
    public function a_medium_risk_permission_needs_no_reauth(): void
    {
        app(MfaReauthGate::class)->clear($this->admin);

        $this->actingAs($this->admin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$this->moderator->id}/permissions", [
                'permissions' => ['moderation.mute_user'],
            ])->assertOk();

        $this->assertTrue($this->resolver()->has($this->moderator->fresh(), 'moderation.mute_user'));
    }

    // ------------------------------------------------------- cache & enforcement

    #[Test]
    public function a_revoke_takes_effect_on_the_very_next_request(): void
    {
        $this->actingAs($this->admin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$this->moderator->id}/permissions", [
                'permissions' => ['moderation.mute_user'],
            ])->assertOk();

        // Warm the cache the way a live session would.
        $this->assertTrue($this->resolver()->has($this->moderator->fresh(), 'moderation.mute_user'));

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->deleteJson($this->base."/admins/{$this->moderator->id}/permissions", [
                'permissions' => ['moderation.mute_user'],
            ])->assertOk();

        // No 300 s lag — "the cache does not delay enforcement".
        $permissions = $this->actingAs($this->moderator->fresh(), 'sanctum-admin')
            ->getJson($this->base.'/auth/me')->json('data.permissions');

        $this->assertNotContains('moderation.mute_user', $permissions);
    }

    #[Test]
    public function a_role_baseline_change_flushes_every_holder(): void
    {
        $this->assertTrue($this->resolver()->has($this->moderator, 'reports.view'));

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson($this->base.'/roles/'.Role::where('key', Role::MODERATOR)->value('id'), [
                'permissions' => ['rooms.view'],
            ])->assertOk();

        $this->assertFalse($this->resolver()->has($this->moderator->fresh(), 'reports.view'));
    }

    #[Test]
    public function an_expired_grant_is_never_effective(): void
    {
        DB::table('admin_user_permission')->insert([
            'admin_user_id' => $this->moderator->id,
            'permission_id' => Permission::where('key', 'rooms.force_close')->value('id'),
            'effect'        => 'allow',
            'expires_at'    => now()->subHour(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->resolver()->flushFor($this->moderator->id);

        $this->assertFalse($this->resolver()->has($this->moderator->fresh(), 'rooms.force_close'));
    }

    #[Test]
    public function a_deny_beats_a_role_baseline(): void
    {
        $this->assertTrue($this->resolver()->has($this->moderator, 'reports.view'));

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$this->moderator->id}/permissions/deny", [
                'permissions' => ['reports.view'],
            ])->assertOk();

        $this->assertFalse($this->resolver()->has($this->moderator->fresh(), 'reports.view'));

        // The viewer still explains why it is missing (GFT-126).
        $detail = collect($this->resolver()->detailedFor($this->moderator->fresh()))
            ->firstWhere('key', 'reports.view');

        $this->assertSame('denied_over_role', $detail['origin']);
    }

    #[Test]
    public function revoking_a_direct_grant_does_not_remove_a_role_baseline(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->deleteJson($this->base."/admins/{$this->moderator->id}/permissions", [
                'permissions' => ['reports.view'],
            ])->assertOk();

        // Nothing was revoked (no direct row), and the criterion is reported honestly.
        $this->assertSame([], $response->json('data.revoked'));
        $this->assertTrue($this->resolver()->has($this->moderator->fresh(), 'reports.view'));
    }

    // ------------------------------------------------------------------- scope

    #[Test]
    public function a_scoped_grant_is_refused_outside_its_category(): void
    {
        $this->actingAs($this->admin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$this->moderator->id}/permissions", [
                'permissions' => ['moderation.mute_user'],
                'scope'       => ['room_categories' => [3]],
            ])->assertOk();

        $gate = app(ScopeGate::class);
        $mod  = $this->moderator->fresh();

        $this->assertNull($gate->reasonToRefuse($mod, 'moderation.mute_user', ['room_category' => 3]));
        $this->assertSame('out_of_category_scope', $gate->reasonToRefuse($mod, 'moderation.mute_user', ['room_category' => 5]));
        // Fails closed when the context is not supplied at all.
        $this->assertSame('out_of_category_scope', $gate->reasonToRefuse($mod, 'moderation.mute_user', []));
    }

    #[Test]
    public function a_shift_window_that_crosses_midnight_is_handled(): void
    {
        $gate   = app(ScopeGate::class);
        $method = new \ReflectionMethod(ScopeGate::class, 'withinShift');
        $method->setAccessible(true);

        $shift = ['from' => '18:00', 'to' => '02:00', 'tz' => 'Asia/Kolkata'];

        $at = fn (string $t) => $method->invoke($gate, $shift, CarbonImmutable::parse($t, 'Asia/Kolkata'));

        $this->assertTrue($at('2026-08-31 20:00'));
        $this->assertTrue($at('2026-08-31 01:00'));
        $this->assertTrue($at('2026-08-31 18:00'));
        $this->assertFalse($at('2026-08-31 02:00'));
        $this->assertFalse($at('2026-08-31 12:00'));
    }

    #[Test]
    public function an_unscoped_permission_is_unrestricted(): void
    {
        $gate = app(ScopeGate::class);

        // rooms.view comes from the role baseline, which carries no scope.
        $this->assertNull($gate->reasonToRefuse($this->moderator, 'rooms.view', []));
    }

    // ------------------------------------------------------------------ logging

    #[Test]
    public function every_grant_and_revoke_writes_both_logs(): void
    {
        $this->actingAs($this->admin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$this->moderator->id}/permissions", [
                'permissions' => ['moderation.mute_user'],
                'reason'      => 'night shift',
            ])->assertOk();

        $grant = PermissionGrantLog::where('target_id', $this->moderator->id)->latest('id')->first();

        $this->assertNotNull($grant);
        $this->assertSame('grant', $grant->action);
        $this->assertSame($this->admin->id, $grant->actor_id);
        $this->assertNull($grant->effect_before);
        $this->assertSame('allow', $grant->effect_after);
        $this->assertSame('night shift', $grant->reason);

        $this->assertTrue(AuditLog::where('action', 'permission.grant')->exists());

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->deleteJson($this->base."/admins/{$this->moderator->id}/permissions", [
                'permissions' => ['moderation.mute_user'],
                'reason'      => 'shift ended',
            ])->assertOk();

        $revoke = PermissionGrantLog::where('target_id', $this->moderator->id)->latest('id')->first();

        $this->assertSame('revoke', $revoke->action);
        $this->assertSame('allow', $revoke->effect_before);
        $this->assertNull($revoke->effect_after);
        $this->assertTrue(AuditLog::where('action', 'permission.revoke')->exists());
    }

    // ------------------------------------------------------------- route gating

    #[Test]
    public function a_moderator_is_refused_on_every_access_route(): void
    {
        $routes = [
            ['get', '/admins'],
            ['get', '/roles'],
            ['get', '/permissions'],
            ['get', '/permissions/grantable'],
            ['get', "/admins/{$this->admin->id}/permissions"],
        ];

        foreach ($routes as [$verb, $path]) {
            $this->actingAs($this->moderator, 'sanctum-admin')
                ->{$verb.'Json'}($this->base.$path)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'PERMISSION_DENIED');
        }
    }

    #[Test]
    public function grantable_reports_only_what_the_caller_may_delegate(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum-admin')
            ->getJson($this->base.'/permissions/grantable')->assertOk();

        $this->assertTrue($response->json('data.can_delegate'));
        $this->assertEqualsCanonicalizing(
            [Role::MANAGER, Role::MODERATOR],
            $response->json('data.grantable_to_roles')
        );

        // The Admin baseline excludes these three, so they must not be offered.
        $offered = collect($response->json('data.modules'))
            ->flatMap(fn ($m) => collect($m['permissions'])->pluck('key'))
            ->all();

        foreach (RoleSeeder::ADMIN_EXCLUDES as $excluded) {
            $this->assertNotContains($excluded, $offered);
        }
    }

    #[Test]
    public function an_unknown_permission_key_is_a_validation_error(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$this->moderator->id}/permissions", [
                'permissions' => ['not.a.real.key'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function a_suspended_admin_loses_access_immediately(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$this->moderator->id}/status", ['status' => 'suspended'])
            ->assertOk();

        $this->actingAs($this->moderator->fresh(), 'sanctum-admin')
            ->getJson($this->base.'/auth/me')
            ->assertStatus(403);
    }
}
