<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\FrontendErrorLog;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** IT Admin epic — the `system.logs_view` gate and the two log endpoints behind it. */
class SystemLogTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    protected function makeAdmin(string $roleKey): AdminUser
    {
        static $seq = 0;
        $seq++;

        return AdminUser::create([
            'name'    => ucfirst($roleKey)." {$seq}",
            'email'   => "{$roleKey}{$seq}@test.local",
            'password' => 'Password12345',
            'role_id' => Role::where('key', $roleKey)->value('id'),
            'status'  => 'active',
        ]);
    }

    #[Test]
    public function it_admin_holds_the_full_permission_catalogue_minus_panel_identity(): void
    {
        $itAdmin = Role::where('key', 'it_admin')->firstOrFail();
        $keys = $itAdmin->permissions()->pluck('key');

        $this->assertContains('system.logs_view', $keys);
        // Everything except the two keys that would let it see who else has panel access.
        $this->assertNotContains('access.admin_manage', $keys);
        $this->assertNotContains('access.permission_grant', $keys);
        $this->assertCount(\App\Models\Permission::count() - 2, $keys);
    }

    #[Test]
    public function the_admin_baseline_does_not_include_system_logs_but_it_admin_does(): void
    {
        $admin = Role::where('key', Role::ADMIN)->firstOrFail();
        $itAdmin = Role::where('key', 'it_admin')->firstOrFail();

        $this->assertFalse($admin->permissions()->where('key', 'system.logs_view')->exists());
        $this->assertTrue($itAdmin->permissions()->where('key', 'system.logs_view')->exists());
    }

    #[Test]
    public function it_admin_cannot_see_the_panel_users_list_not_even_super_admin(): void
    {
        $this->actingAs($this->makeAdmin('it_admin'), 'sanctum-admin')
            ->getJson($this->base.'/admins')
            ->assertStatus(403);

        $this->actingAs($this->makeAdmin('it_admin'), 'sanctum-admin')
            ->getJson($this->base.'/admins/'.$this->makeAdmin(Role::SUPER_ADMIN)->id.'/permissions')
            ->assertStatus(403);
    }

    #[Test]
    public function it_admin_can_read_the_laravel_log(): void
    {
        $this->actingAs($this->makeAdmin('it_admin'), 'sanctum-admin')
            ->getJson($this->base.'/system/logs/laravel')
            ->assertOk()
            ->assertJsonStructure(['data' => ['entries', 'truncated', 'file_size']]);
    }

    #[Test]
    public function super_admin_is_refused_despite_the_blanket_permission_bypass(): void
    {
        $this->actingAs($this->makeAdmin(Role::SUPER_ADMIN), 'sanctum-admin')
            ->getJson($this->base.'/system/logs/laravel')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');

        $this->actingAs($this->makeAdmin(Role::SUPER_ADMIN), 'sanctum-admin')
            ->getJson($this->base.'/system/logs/frontend')
            ->assertStatus(403);
    }

    #[Test]
    public function a_role_without_the_permission_is_refused(): void
    {
        $this->actingAs($this->makeAdmin(Role::MANAGER), 'sanctum-admin')
            ->getJson($this->base.'/system/logs/laravel')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');

        $this->actingAs($this->makeAdmin(Role::MANAGER), 'sanctum-admin')
            ->getJson($this->base.'/system/logs/frontend')
            ->assertStatus(403);
    }

    #[Test]
    public function any_authenticated_admin_may_report_a_frontend_error(): void
    {
        // A Manager cannot read the collected list, but self-reporting their own browser
        // error is not gated behind the same permission.
        $this->actingAs($this->makeAdmin(Role::MANAGER), 'sanctum-admin')
            ->postJson($this->base.'/system/logs/frontend', [
                'message'    => 'TypeError: Cannot read properties of undefined',
                'stack'      => "at Foo.vue:12\nat mount",
                'source_url' => '/rooms/42',
            ])
            ->assertStatus(201);

        $this->assertDatabaseCount('frontend_error_logs', 1);
        $this->assertSame('error', FrontendErrorLog::first()->level);
    }

    #[Test]
    public function it_admin_sees_reported_frontend_errors(): void
    {
        FrontendErrorLog::create([
            'level'      => 'error',
            'message'    => 'Something broke',
            'source_url' => '/vip',
        ]);

        $this->actingAs($this->makeAdmin('it_admin'), 'sanctum-admin')
            ->getJson($this->base.'/system/logs/frontend')
            ->assertOk()
            ->assertJsonPath('data.0.message', 'Something broke');
    }
}
