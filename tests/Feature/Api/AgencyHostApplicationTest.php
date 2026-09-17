<?php

namespace Tests\Feature\Api;

use App\Models\AdminUser;
use App\Models\Agency;
use App\Models\Host;
use App\Models\HostApplication;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use PHPUnit\Framework\Attributes\Test;

/**
 * D.9a — the agency owner reviews applications sent to their own agency; an admin keeps a
 * separate, overriding path (Admin\HostController / tests/Feature/Admin/AgencyHostTest.php).
 */
class AgencyHostApplicationTest extends MobileTestCase
{
    protected function makeAgency(User $owner, string $status = Agency::APPROVED): Agency
    {
        static $seq = 0;
        $seq++;

        return Agency::create([
            'code'          => 'AGY-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'name'          => "Agency {$seq}",
            'owner_user_id' => $owner->id,
            'documents'     => [['type' => 'gst', 'url' => 'https://cdn.example.com/g.pdf']],
            'status'        => $status,
        ]);
    }

    protected function makeAdmin(): AdminUser
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        static $seq = 0;
        $seq++;

        return AdminUser::create([
            'name' => 'Admin', 'email' => "agency-admin{$seq}@test.local", 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::SUPER_ADMIN)->value('id'), 'status' => 'active',
        ]);
    }

    #[Test]
    public function an_agency_owner_sees_only_applications_sent_to_their_own_agency(): void
    {
        $owner = $this->makeUser('Owner');
        $agency = $this->makeAgency($owner);
        $other = $this->makeAgency($this->makeUser('Other Owner'));

        $applicant = $this->makeUser('Applicant');
        HostApplication::create(['user_id' => $applicant->id, 'agency_id' => $agency->id]);
        HostApplication::create(['user_id' => $this->makeUser('Elsewhere')->id, 'agency_id' => $other->id]);

        $this->actingAsUser($owner);

        $response = $this->getJson("{$this->base}/agency/host-applications")->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($applicant->guftagu_id, $response->json('data.0.guftagu_id'));
    }

    #[Test]
    public function an_agency_owner_can_approve_an_application_to_their_own_agency(): void
    {
        $owner = $this->makeUser('Owner');
        $agency = $this->makeAgency($owner);
        $applicant = $this->makeUser('Applicant');
        $application = HostApplication::create(['user_id' => $applicant->id, 'agency_id' => $agency->id]);

        $this->actingAsUser($owner);

        $this->postJson("{$this->base}/agency/host-applications/{$application->id}/approve")
            ->assertOk();

        $host = Host::where('user_id', $applicant->id)->firstOrFail();
        $this->assertSame(Host::APPROVED, $host->status);
        $this->assertSame($agency->id, $host->agency_id);
        $this->assertDatabaseHas('agency_members', [
            'agency_id' => $agency->id, 'user_id' => $applicant->id, 'role' => 'host', 'is_active' => true,
        ]);

        $application->refresh();
        $this->assertSame(HostApplication::APPROVED, $application->status);
        $this->assertSame($owner->id, $application->reviewed_by_user_id);
        $this->assertNull($application->reviewed_by);
    }

    #[Test]
    public function an_agency_owner_can_reject_an_application_with_a_reason(): void
    {
        $owner = $this->makeUser('Owner');
        $agency = $this->makeAgency($owner);
        $applicant = $this->makeUser('Applicant');
        $application = HostApplication::create(['user_id' => $applicant->id, 'agency_id' => $agency->id]);

        $this->actingAsUser($owner);

        $this->postJson("{$this->base}/agency/host-applications/{$application->id}/reject", ['reason' => 'Not a fit.'])
            ->assertOk();

        $application->refresh();
        $this->assertSame(HostApplication::REJECTED, $application->status);
        $this->assertSame('Not a fit.', $application->reason);
        $this->assertSame($owner->id, $application->reviewed_by_user_id);
    }

    #[Test]
    public function a_user_who_does_not_own_the_agency_cannot_review_its_applications(): void
    {
        $owner = $this->makeUser('Owner');
        $agency = $this->makeAgency($owner);
        $stranger = $this->makeUser('Stranger');
        $applicant = $this->makeUser('Applicant');
        $application = HostApplication::create(['user_id' => $applicant->id, 'agency_id' => $agency->id]);

        $this->actingAsUser($stranger);

        $this->postJson("{$this->base}/agency/host-applications/{$application->id}/approve")
            ->assertStatus(403);
    }

    #[Test]
    public function a_host_can_reapply_after_being_rejected(): void
    {
        $owner = $this->makeUser('Owner');
        $agency = $this->makeAgency($owner);
        $applicant = $this->makeUser('Applicant');
        $first = HostApplication::create(['user_id' => $applicant->id, 'agency_id' => $agency->id]);

        $this->actingAsUser($owner);

        $this->postJson("{$this->base}/agency/host-applications/{$first->id}/reject", ['reason' => 'Try again later.'])
            ->assertOk();

        $this->actingAsUser($applicant);

        $this->postJson("{$this->base}/host/apply", ['agency_id' => $agency->id])
            ->assertCreated();

        $this->assertSame(2, HostApplication::where('user_id', $applicant->id)->count());
    }

    #[Test]
    public function an_owner_cannot_reverse_their_own_rejection_but_an_admin_can(): void
    {
        $owner = $this->makeUser('Owner');
        $agency = $this->makeAgency($owner);
        $applicant = $this->makeUser('Applicant');
        $application = HostApplication::create(['user_id' => $applicant->id, 'agency_id' => $agency->id]);

        $this->actingAsUser($owner);

        $this->postJson("{$this->base}/agency/host-applications/{$application->id}/reject", ['reason' => 'By mistake.'])
            ->assertOk();

        // The owner cannot undo their own decision.
        $this->postJson("{$this->base}/agency/host-applications/{$application->id}/approve")
            ->assertStatus(400);

        // An admin can override it.
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum-admin');

        $this->postJson("/api/v1/admin/host-applications/{$application->id}/approve")
            ->assertOk();

        $application->refresh();
        $this->assertSame(HostApplication::APPROVED, $application->status);
        $this->assertSame($admin->id, $application->reviewed_by);
        $this->assertNull($application->reviewed_by_user_id);

        $host = Host::where('user_id', $applicant->id)->firstOrFail();
        $this->assertSame(Host::APPROVED, $host->status);
    }
}
