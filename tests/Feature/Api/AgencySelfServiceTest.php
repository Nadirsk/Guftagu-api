<?php

namespace Tests\Feature\Api;

use App\Models\AdminUser;
use App\Models\Agency;
use App\Models\Host;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use PHPUnit\Framework\Attributes\Test;

/**
 * D.9a — a user creates their own agency; it stays unusable until an admin approves it, and
 * approval itself refuses one with no documents on file (see AgencyHostTest for that rule).
 */
class AgencySelfServiceTest extends MobileTestCase
{
    protected function makeAdmin(): AdminUser
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        static $seq = 0;
        $seq++;

        return AdminUser::create([
            'name' => 'Admin', 'email' => "self-service-admin{$seq}@test.local", 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::SUPER_ADMIN)->value('id'), 'status' => 'active',
        ]);
    }

    #[Test]
    public function a_user_can_apply_to_create_their_own_agency(): void
    {
        $owner = $this->makeUser('Owner');
        $this->actingAsUser($owner);

        $response = $this->postJson("{$this->base}/agency/apply", [
            'name'        => 'Star Talkers',
            'description' => 'A community of hosts.',
            'documents'   => [['type' => 'gst', 'url' => 'https://cdn.example.com/gst.pdf']],
        ])->assertCreated();

        $agency = Agency::findOrFail($response->json('data.id'));
        $this->assertSame($owner->id, $agency->owner_user_id);
        $this->assertSame(Agency::PENDING, $agency->status);
        $this->assertCount(1, $agency->documents);
    }

    #[Test]
    public function a_user_cannot_apply_again_while_an_application_is_pending(): void
    {
        $owner = $this->makeUser('Owner');
        $this->actingAsUser($owner);

        $this->postJson("{$this->base}/agency/apply", ['name' => 'First Try'])->assertCreated();

        $this->postJson("{$this->base}/agency/apply", ['name' => 'Second Try'])
            ->assertStatus(400);
    }

    #[Test]
    public function a_user_can_check_their_own_agency_status(): void
    {
        $owner = $this->makeUser('Owner');
        $this->actingAsUser($owner);

        $this->postJson("{$this->base}/agency/apply", ['name' => 'Star Talkers'])->assertCreated();

        $this->getJson("{$this->base}/agency/status")
            ->assertOk()
            ->assertJsonPath('data.status', Agency::PENDING)
            ->assertJsonPath('data.is_approved', false);
    }

    #[Test]
    public function an_admin_still_cannot_approve_a_self_created_agency_with_no_documents(): void
    {
        $owner = $this->makeUser('Owner');
        $this->actingAsUser($owner);

        $id = $this->postJson("{$this->base}/agency/apply", ['name' => 'No Docs Yet'])
            ->assertCreated()
            ->json('data.id');

        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum-admin');

        $this->postJson("/api/v1/admin/agencies/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DOCUMENTS_MISSING');
    }

    #[Test]
    public function once_approved_the_owner_sees_it_reflected_in_their_own_status(): void
    {
        $owner = $this->makeUser('Owner');
        $this->actingAsUser($owner);

        $id = $this->postJson("{$this->base}/agency/apply", [
            'name'      => 'Star Talkers',
            'documents' => [['type' => 'gst', 'url' => 'https://cdn.example.com/gst.pdf']],
        ])->assertCreated()->json('data.id');

        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum-admin');

        $this->postJson("/api/v1/admin/agencies/{$id}/approve")->assertOk();

        $this->actingAsUser($owner);

        $this->getJson("{$this->base}/agency/status")
            ->assertOk()
            ->assertJsonPath('data.status', Agency::APPROVED)
            ->assertJsonPath('data.is_approved', true);
    }

    #[Test]
    public function approving_the_agency_makes_its_owner_an_approved_host_in_it_too(): void
    {
        $owner = $this->makeUser('Owner');
        $this->actingAsUser($owner);

        $id = $this->postJson("{$this->base}/agency/apply", [
            'name'      => 'Star Talkers',
            'documents' => [['type' => 'gst', 'url' => 'https://cdn.example.com/gst.pdf']],
        ])->assertCreated()->json('data.id');

        // Before approval the owner is not yet a host of anything.
        $this->getJson("{$this->base}/host/status")->assertOk()->assertJsonPath('data.is_host', false);

        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum-admin');
        $this->postJson("/api/v1/admin/agencies/{$id}/approve")->assertOk();

        $host = Host::where('user_id', $owner->id)->firstOrFail();
        $this->assertSame(Host::APPROVED, $host->status);
        $this->assertSame($id, $host->agency_id);
        $this->assertDatabaseHas('agency_members', [
            'agency_id' => $id, 'user_id' => $owner->id, 'role' => 'owner', 'is_active' => true,
        ]);

        // No separate host/apply round trip needed — the owner is already a host.
        $this->actingAsUser($owner);
        $this->getJson("{$this->base}/host/status")->assertOk()->assertJsonPath('data.is_host', true);
    }

    #[Test]
    public function a_user_with_an_approved_agency_cannot_apply_for_another(): void
    {
        $owner = $this->makeUser('Owner');
        $this->actingAsUser($owner);

        $id = $this->postJson("{$this->base}/agency/apply", [
            'name'      => 'Star Talkers',
            'documents' => [['type' => 'gst', 'url' => 'https://cdn.example.com/gst.pdf']],
        ])->assertCreated()->json('data.id');

        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum-admin');
        $this->postJson("/api/v1/admin/agencies/{$id}/approve")->assertOk();

        $this->actingAsUser($owner);

        $this->postJson("{$this->base}/agency/apply", ['name' => 'Second Agency'])
            ->assertStatus(400);
    }
}
