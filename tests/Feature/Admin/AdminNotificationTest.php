<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Notification;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin panel's own notification inbox — C.5a and general. Every role gets one; there
 * is no permission key because it is always the caller's own rows.
 */
class AdminNotificationTest extends TestCase
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
            'name' => "Admin {$seq}", 'email' => "an{$seq}@test.local", 'password' => 'Password12345',
            'role_id' => Role::where('key', $roleKey)->value('id'), 'status' => 'active',
        ]);
    }

    #[Test]
    public function every_role_sees_its_own_notifications_and_an_unread_count(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::ADMIN, Role::MANAGER, Role::MODERATOR] as $roleKey) {
            $admin = $this->makeAdmin($roleKey);

            Notification::create([
                'admin_user_id' => $admin->id, 'type' => 'support_escalation',
                'title' => 'A ticket needs you', 'body' => 'Escalated.', 'channel' => 'in_app',
            ]);
            Notification::create([
                'admin_user_id' => $admin->id, 'type' => 'support_escalation',
                'title' => 'Read already', 'body' => 'Seen it.', 'channel' => 'in_app',
                'is_read' => true, 'read_at' => now(),
            ]);

            $response = $this->actingAs($admin, 'sanctum-admin')
                ->getJson("{$this->base}/notifications")
                ->assertOk();

            $this->assertCount(2, $response->json('data'), "role {$roleKey} should see both of its own rows");
            $this->assertSame(1, $response->json('meta.unread_count'), "role {$roleKey} should have one unread");
        }
    }

    #[Test]
    public function one_admins_notifications_are_invisible_to_another(): void
    {
        $mine = $this->makeAdmin(Role::MODERATOR);
        $theirs = $this->makeAdmin(Role::MODERATOR);

        Notification::create([
            'admin_user_id' => $theirs->id, 'type' => 'support_escalation',
            'title' => 'Not yours', 'body' => 'Belongs to someone else.', 'channel' => 'in_app',
        ]);

        $response = $this->actingAs($mine, 'sanctum-admin')
            ->getJson("{$this->base}/notifications")
            ->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.unread_count'));
    }

    #[Test]
    public function marking_one_read_updates_only_that_row(): void
    {
        $admin = $this->makeAdmin(Role::ADMIN);

        $unread = Notification::create([
            'admin_user_id' => $admin->id, 'type' => 'support_escalation',
            'title' => 'One', 'body' => 'Body.', 'channel' => 'in_app',
        ]);
        $other = Notification::create([
            'admin_user_id' => $admin->id, 'type' => 'support_escalation',
            'title' => 'Two', 'body' => 'Body.', 'channel' => 'in_app',
        ]);

        $this->actingAs($admin, 'sanctum-admin')
            ->postJson("{$this->base}/notifications/{$unread->id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);

        $this->assertTrue($unread->fresh()->is_read);
        $this->assertFalse($other->fresh()->is_read);
    }

    #[Test]
    public function marking_another_admins_notification_read_is_refused(): void
    {
        $mine = $this->makeAdmin(Role::MODERATOR);
        $theirs = $this->makeAdmin(Role::MODERATOR);

        $notification = Notification::create([
            'admin_user_id' => $theirs->id, 'type' => 'support_escalation',
            'title' => 'Not yours', 'body' => 'Belongs to someone else.', 'channel' => 'in_app',
        ]);

        $this->actingAs($mine, 'sanctum-admin')
            ->postJson("{$this->base}/notifications/{$notification->id}/read")
            ->assertStatus(403);

        $this->assertFalse($notification->fresh()->is_read);
    }

    #[Test]
    public function read_all_clears_every_unread_row_for_that_admin_only(): void
    {
        $admin = $this->makeAdmin(Role::SUPER_ADMIN);
        $other = $this->makeAdmin(Role::ADMIN);

        Notification::create(['admin_user_id' => $admin->id, 'type' => 'x', 'title' => 'A', 'body' => 'b', 'channel' => 'in_app']);
        Notification::create(['admin_user_id' => $admin->id, 'type' => 'x', 'title' => 'B', 'body' => 'b', 'channel' => 'in_app']);
        $othersUnread = Notification::create(['admin_user_id' => $other->id, 'type' => 'x', 'title' => 'C', 'body' => 'b', 'channel' => 'in_app']);

        $this->actingAs($admin, 'sanctum-admin')
            ->postJson("{$this->base}/notifications/read-all")
            ->assertOk();

        $this->assertSame(0, Notification::where('admin_user_id', $admin->id)->unread()->count());
        $this->assertTrue($othersUnread->fresh()->is_read === false);
    }
}
