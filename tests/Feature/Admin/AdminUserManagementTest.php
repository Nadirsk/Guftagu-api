<?php

namespace Tests\Feature\Admin;

use App\Mail\AdminWelcomeMail;
use App\Models\AdminUser;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GFT-127 — creating a Manager/Moderator/Admin panel account mails the new user their
 * sign-in credentials and the admin panel URL (AdminUserController::store).
 */
class AdminUserManagementTest extends TestCase
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

    #[Test]
    public function creating_a_panel_user_mails_them_the_credentials_and_panel_url(): void
    {
        Mail::fake();

        config(['guftagu.frontend_url' => 'https://panel.example.com']);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/admins", [
                'name'     => 'New Manager',
                'email'    => 'new.manager@test.local',
                'password' => 'SomeStrongPassw0rd',
                'role'     => Role::MANAGER,
            ])
            ->assertCreated();

        $admin = AdminUser::where('email', 'new.manager@test.local')->firstOrFail();

        Mail::assertSent(AdminWelcomeMail::class, function (AdminWelcomeMail $mail) use ($admin) {
            return $mail->hasTo($admin->email)
                && $mail->admin->is($admin)
                && $mail->password === 'SomeStrongPassw0rd'
                && $mail->roleName === 'Manager'
                && $mail->panelUrl === 'https://panel.example.com';
        });
    }

    #[Test]
    public function a_mail_delivery_failure_does_not_undo_the_created_account(): void
    {
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP is down'));

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/admins", [
                'name'     => 'Resilient Manager',
                'email'    => 'resilient@test.local',
                'password' => 'SomeStrongPassw0rd',
                'role'     => Role::MANAGER,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('admin_users', ['email' => 'resilient@test.local']);
    }

    #[Test]
    public function a_panel_users_sign_in_email_can_be_edited(): void
    {
        $manager = AdminUser::create([
            'name' => 'Manager', 'email' => 'old.manager@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::MANAGER)->value('id'), 'status' => 'active',
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson("{$this->base}/admins/{$manager->id}", [
                'email' => 'new.manager@test.local',
            ])
            ->assertOk()
            ->assertJsonPath('data.email', 'new.manager@test.local');

        $this->assertSame('new.manager@test.local', $manager->fresh()->email);
    }

    #[Test]
    public function editing_an_email_to_one_already_in_use_is_refused(): void
    {
        $manager = AdminUser::create([
            'name' => 'Manager', 'email' => 'manager@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::MANAGER)->value('id'), 'status' => 'active',
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson("{$this->base}/admins/{$manager->id}", [
                'email' => 'super@test.local',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame('manager@test.local', $manager->fresh()->email);
    }

    #[Test]
    public function editing_a_field_without_touching_email_does_not_require_it_to_be_unique_against_itself(): void
    {
        // The unique rule must ignore the admin's own row, or every no-op save on name
        // would fail because the account's own email "collides" with itself.
        $manager = AdminUser::create([
            'name' => 'Manager', 'email' => 'manager@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::MANAGER)->value('id'), 'status' => 'active',
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson("{$this->base}/admins/{$manager->id}", [
                'email' => 'manager@test.local',
                'name'  => 'Renamed Manager',
            ])
            ->assertOk();

        $this->assertSame('Renamed Manager', $manager->fresh()->name);
    }
}
