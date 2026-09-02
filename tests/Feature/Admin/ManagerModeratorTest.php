<?php

namespace Tests\Feature\Admin;

use App\Domain\Moderation\BanPolicy;
use App\Domain\Support\SupportService;
use App\Models\AdminUser;
use App\Models\Agency;
use App\Models\Banner;
use App\Models\Broadcast;
use App\Models\Event;
use App\Models\Notification;
use App\Models\Report;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use Database\Seeders\EconomySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** FR.B and the remaining FR.C acceptance criteria. */
class ManagerModeratorTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected AdminUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class, EconomySeeder::class]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->superAdmin = $this->makeAdmin('Super', Role::SUPER_ADMIN);
    }

    protected function makeAdmin(string $name, string $roleKey): AdminUser
    {
        static $seq = 0;
        $seq++;

        return AdminUser::create([
            'name' => $name, 'email' => "bc{$seq}@test.local", 'password' => 'Password12345',
            'role_id' => Role::where('key', $roleKey)->value('id'), 'status' => 'active',
        ]);
    }

    protected function grant(AdminUser $admin, string $key, ?array $scope = null): void
    {
        DB::table('admin_user_permission')->insert([
            'admin_user_id' => $admin->id,
            'permission_id' => DB::table('permissions')->where('key', $key)->value('id'),
            'effect'        => 'allow',
            'granted_by'    => $this->superAdmin->id,
            'scope'         => $scope === null ? null : json_encode($scope),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    protected function makeUser(): User
    {
        static $seq = 0;
        $seq++;

        $user = User::create([
            'guftagu_id' => 'BC'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
            'phone'      => '+9198209'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'status'     => User::STATUS_ACTIVE,
            'agora_uid'  => 900000 + $seq,
        ]);

        UserProfile::create(['user_id' => $user->id, 'display_name' => "Person {$seq}"]);
        Wallet::create(['user_id' => $user->id]);

        return $user;
    }

    protected function makeReport(array $attributes = []): Report
    {
        return Report::create(array_merge([
            'target_type' => 'user',
            'target_id'   => (string) $this->makeUser()->id,
            'category'    => 'abuse',
            'description' => 'Something happened.',
            'priority'    => 'medium',
            'status'      => Report::OPEN,
        ], $attributes));
    }

    // -------------------------------------------------------------------- B.2a

    #[Test]
    public function a_manager_can_submit_an_agency_but_not_approve_it(): void
    {
        $manager = $this->makeAdmin('Manager', Role::MANAGER);

        $id = $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/agencies", ['name' => 'Manager submission'])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(Agency::PENDING, Agency::find($id)->status);

        // B.2a, verbatim: the approve endpoint requires `agency.approve`, which the
        // Manager baseline excludes.
        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/agencies/{$id}/approve")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');

        $this->assertSame(Agency::PENDING, Agency::find($id)->status);
    }

    // -------------------------------------------------------------------- B.3a

    #[Test]
    public function a_manager_schedules_an_event_as_a_draft_and_cannot_publish_it(): void
    {
        $manager = $this->makeAdmin('Manager', Role::MANAGER);

        $id = $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/events", [
                'type' => 'tournament', 'title_en' => 'Weekend cup',
                'starts_at' => now()->addDay()->toIso8601String(),
                'ends_at' => now()->addDays(3)->toIso8601String(),
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(Event::DRAFT, Event::find($id)->status);

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/events/{$id}/publish")
            ->assertStatus(403);

        // Still a draft, so it does not appear in the app.
        $this->assertSame(Event::DRAFT, Event::find($id)->status);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/events/{$id}/publish")
            ->assertOk();

        $this->assertSame(Event::SCHEDULED, Event::find($id)->status);
    }

    // -------------------------------------------------------------------- B.3b

    #[Test]
    public function a_manager_submitted_banner_does_not_go_live_without_approval(): void
    {
        $manager = $this->makeAdmin('Manager', Role::MANAGER);

        $data = $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/content/banners", [
                'title' => 'Manager banner',
                'image_url' => 'https://cdn.example.com/m.jpg',
                'placement' => 'home_top',
                'starts_at' => now()->subHour()->toDateTimeString(),
            ])
            ->assertCreated()
            ->json('data');

        // Active and inside its window, and still not showing.
        $this->assertSame('awaiting_approval', $data['state']);
        $this->assertFalse($data['is_live']);

        $banner = Banner::find($data['id']);
        $this->assertFalse(Banner::query()->live()->pluck('id')->contains($banner->id));

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/content/banners/{$banner->id}/approve")
            ->assertStatus(403);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/content/banners/{$banner->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.is_live', true);

        $this->assertTrue(Banner::query()->live()->pluck('id')->contains($banner->id));
    }

    #[Test]
    public function editing_an_approved_banner_without_approval_rights_sends_it_back(): void
    {
        // Otherwise a Manager gets a harmless banner signed off and then swaps the image.
        $manager = $this->makeAdmin('Manager', Role::MANAGER);

        $banner = Banner::create([
            'title' => 'Approved', 'image_url' => 'https://cdn.example.com/a.jpg',
            'placement' => 'home_top', 'is_active' => true,
            'approved_by' => $this->superAdmin->id,
        ]);

        $this->actingAs($manager, 'sanctum-admin')
            ->patchJson("{$this->base}/content/banners/{$banner->id}", [
                'image_url' => 'https://cdn.example.com/swapped.jpg',
            ])
            ->assertOk()
            ->assertJsonPath('data.state', 'awaiting_approval');

        $this->assertNull($banner->fresh()->approved_by);
    }

    #[Test]
    public function an_admin_editing_their_own_approved_banner_keeps_it_live(): void
    {
        $banner = Banner::create([
            'title' => 'Approved', 'image_url' => 'https://cdn.example.com/a.jpg',
            'placement' => 'home_top', 'is_active' => true,
            'approved_by' => $this->superAdmin->id,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson("{$this->base}/content/banners/{$banner->id}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.state', 'live');
    }

    // -------------------------------------------------------------------- B.3c

    #[Test]
    public function a_bulk_promotion_features_every_selected_room_and_is_logged(): void
    {
        $category = RoomCategory::create(['key' => 'music', 'name_en' => 'Music', 'sort_order' => 1]);

        $rooms = collect(range(1, 5))->map(function (int $i) use ($category) {
            $owner = $this->makeUser();

            return Room::create([
                'room_code' => 'RM'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'owner_id'  => $owner->id,
                'category_id' => $category->id,
                'name'      => "Room {$i}",
                'seat_count' => 8,
                'status'    => 'live',
            ]);
        });

        $until = now()->addDays(3);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/rooms/feature-bulk", [
                'room_ids' => $rooms->pluck('id')->all(),
                'until'    => $until->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.featured', 5);

        foreach ($rooms as $room) {
            $this->assertTrue($room->fresh()->is_featured);
        }

        $this->assertDatabaseHas('audit_logs', ['action' => 'rooms.feature_bulk']);
    }

    #[Test]
    public function a_bulk_promotion_is_all_or_nothing(): void
    {
        $category = RoomCategory::create(['key' => 'talk', 'name_en' => 'Talk', 'sort_order' => 2]);

        $room = Room::create([
            'room_code' => 'RMONE1', 'owner_id' => $this->makeUser()->id,
            'category_id' => $category->id, 'name' => 'Real', 'seat_count' => 8, 'status' => 'live',
        ]);

        // One id that does not exist. A promotion that features some and fails on the rest
        // leaves a half-run campaign somebody has to reconstruct.
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/rooms/feature-bulk", [
                'room_ids' => [$room->id, 999999],
                'until'    => now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(422);

        $this->assertFalse($room->fresh()->is_featured);
    }

    // -------------------------------------------------------------------- B.4a

    #[Test]
    public function replying_notifies_the_user_and_stops_the_first_response_timer(): void
    {
        $user = $this->makeUser();
        $manager = $this->makeAdmin('Manager', Role::MANAGER);

        $ticket = app(SupportService::class)->open([
            'subject' => 'Cannot recharge', 'description' => 'The payment failed twice.',
            'category' => 'payment', 'priority' => 'high',
        ], $user);

        $this->assertNull($ticket->first_response_at);

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/support/{$ticket->id}/reply", [
                'body' => 'Sorry about that — we have refunded the failed attempt.',
            ])
            ->assertOk();

        $fresh = $ticket->fresh();

        $this->assertNotNull($fresh->first_response_at);
        $this->assertNotNull($fresh->firstResponseMinutes());

        // B.4a: the user receives an in-app notification.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id, 'type' => 'support_reply',
        ]);
    }

    #[Test]
    public function an_internal_note_does_not_stop_the_clock(): void
    {
        // The person waiting has not heard anything, so the promise has not been kept.
        $user = $this->makeUser();
        $manager = $this->makeAdmin('Manager', Role::MANAGER);

        $ticket = app(SupportService::class)->open([
            'subject' => 'Query', 'description' => 'A question.',
        ], $user);

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/support/{$ticket->id}/reply", [
                'body' => 'Checking with payments before we answer.',
                'is_internal' => true,
            ])
            ->assertOk();

        $this->assertNull($ticket->fresh()->first_response_at);
        $this->assertDatabaseMissing('notifications', ['user_id' => $user->id, 'type' => 'support_reply']);
    }

    #[Test]
    public function an_internal_note_is_flagged_so_it_cannot_be_mistaken_for_a_reply(): void
    {
        $manager = $this->makeAdmin('Manager', Role::MANAGER);

        $ticket = app(SupportService::class)->open([
            'subject' => 'Query', 'description' => 'A question.',
        ], $this->makeUser());

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/support/{$ticket->id}/reply", [
                'body' => 'Probably a duplicate of TKT-000001.', 'is_internal' => true,
            ])->assertOk();

        $messages = $this->actingAs($manager, 'sanctum-admin')
            ->getJson("{$this->base}/support/{$ticket->id}")->json('data.messages');

        $note = collect($messages)->firstWhere('is_internal', true);

        $this->assertNotNull($note, 'An internal note must be distinguishable from a reply.');
    }

    #[Test]
    public function the_sla_promise_is_frozen_at_the_moment_the_ticket_is_raised(): void
    {
        // Tightening the policy next month must not retroactively breach last month.
        $urgent = app(SupportService::class)->open([
            'subject' => 'Locked out', 'description' => 'Cannot log in.', 'priority' => 'urgent',
        ], $this->makeUser());

        $normal = app(SupportService::class)->open([
            'subject' => 'Idea', 'description' => 'A suggestion.', 'priority' => 'low',
        ], $this->makeUser());

        $this->assertSame(30, $urgent->sla_first_response_minutes);
        $this->assertSame(480, $normal->sla_first_response_minutes);
    }

    #[Test]
    public function a_late_ticket_shows_as_breached_with_no_job_having_run(): void
    {
        $ticket = app(SupportService::class)->open([
            'subject' => 'Waiting', 'description' => 'Anyone?', 'priority' => 'urgent',
        ], $this->makeUser());

        $ticket->forceFill(['created_at' => now()->subHours(3)])->save();

        $fresh = $ticket->fresh();

        $this->assertTrue($fresh->breachedFirstResponse());
        $this->assertSame('response_breached', $fresh->slaState());
        // The SQL scope has to agree with the PHP predicate.
        $this->assertTrue(SupportTicket::query()->breaching()->pluck('id')->contains($fresh->id));
    }

    // -------------------------------------------------------------------- B.4b

    #[Test]
    public function flagging_a_room_creates_a_high_priority_report_naming_the_manager(): void
    {
        $manager = $this->makeAdmin('Manager', Role::MANAGER);

        $reportId = $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/support/flag-room", [
                'room_id' => 42, 'reason' => 'Host is soliciting off-platform payment.',
            ])
            ->assertCreated()
            ->json('data.report_id');

        $report = Report::findOrFail($reportId);

        $this->assertSame('high', $report->priority);
        $this->assertSame('room', $report->target_type);
        $this->assertStringContainsString($manager->name, $report->description);

        // It is in the Moderator queue immediately — same table, nothing to sync.
        $moderator = $this->makeAdmin('Mod', Role::MODERATOR);

        $ids = collect(
            $this->actingAs($moderator, 'sanctum-admin')->getJson("{$this->base}/reports")->json('data')
        )->pluck('id');

        $this->assertTrue($ids->contains($report->id));
    }

    // -------------------------------------------------------------------- B.4c

    #[Test]
    public function escalating_notifies_the_named_admin_and_records_it_on_the_ticket(): void
    {
        $manager = $this->makeAdmin('Manager', Role::MANAGER);
        $admin = $this->makeAdmin('Admin', Role::ADMIN);

        $ticket = app(SupportService::class)->open([
            'subject' => 'Stuck withdrawal', 'description' => 'Three days, no payout.',
            'priority' => 'high',
        ], $this->makeUser());

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/support/{$ticket->id}/escalate", [
                'admin_user_id' => $admin->id,
                'note' => 'Gateway says paid, wallet says pending. Needs finance.',
            ])
            ->assertOk();

        $fresh = $ticket->fresh();

        // Both halves: recorded on the ticket…
        $this->assertSame($admin->id, $fresh->escalated_to);
        $this->assertNotNull($fresh->escalated_at);
        $this->assertStringContainsString('Needs finance', $fresh->escalation_note);

        // …and the named Admin is notified.
        $this->assertDatabaseHas('notifications', [
            'admin_user_id' => $admin->id, 'type' => 'support_escalation',
        ]);

        // The thread says so too, so the history is readable without joining tables.
        $this->assertTrue(
            SupportTicketMessage::where('ticket_id', $ticket->id)
                ->where('sender_type', SupportTicketMessage::FROM_SYSTEM)
                ->where('body', 'like', '%Escalated to%')
                ->exists(),
        );
    }

    #[Test]
    public function a_moderator_cannot_reach_the_support_inbox(): void
    {
        $this->actingAs($this->makeAdmin('Mod', Role::MODERATOR), 'sanctum-admin')
            ->getJson("{$this->base}/support")
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------- B.5b

    #[Test]
    public function a_campaign_outcome_labels_recharges_as_correlation_not_attribution(): void
    {
        $user = $this->makeUser();
        $wallet = Wallet::where('user_id', $user->id)->firstOrFail();

        $broadcast = Broadcast::create([
            'title' => 'Coin sale', 'body' => 'Half price today.',
            'audience' => 'all', 'channels' => ['in_app'],
            'status' => Broadcast::SENT, 'sent_count' => 1, 'audience_count' => 1,
            'sent_at' => now()->subHours(2),
        ]);

        Notification::create([
            'user_id' => $user->id, 'type' => 'broadcast', 'title' => 'Coin sale',
            'body' => 'Half price today.', 'data' => ['broadcast_uuid' => $broadcast->uuid],
            'channel' => 'in_app', 'sent_at' => now()->subHours(2),
        ]);

        DB::table('coin_transactions')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'wallet_id' => $wallet->id, 'user_id' => $user->id,
            'direction' => 'credit', 'amount' => 500, 'balance_before' => 0,
            'balance_after' => 500, 'type' => 'recharge', 'created_at' => now()->subHour(),
        ]);

        $result = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/broadcasts/{$broadcast->id}/outcome")
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $result['recharges']);
        $this->assertSame(500, $result['coins_purchased']);
        // The honest label. Nobody clicked a tracked link.
        $this->assertSame('correlated', $result['attribution']);
        $this->assertNull($result['open_rate']);
    }

    // -------------------------------------------------------------------- C.3a

    #[Test]
    public function a_claimed_report_is_not_actionable_by_another_moderator(): void
    {
        $first = $this->makeAdmin('First mod', Role::MODERATOR);
        $second = $this->makeAdmin('Second mod', Role::MODERATOR);
        $this->grant($first, 'reports.action');
        $this->grant($second, 'reports.action');

        $report = $this->makeReport();

        $this->actingAs($first, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/claim")
            ->assertOk();

        $this->actingAs($second, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/claim")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ALREADY_CLAIMED');

        $this->actingAs($second, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", [
                'action' => 'warn', 'note' => 'Trying to jump in.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ALREADY_CLAIMED');

        // The holder can still act.
        $this->actingAs($first, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", [
                'action' => 'warn', 'note' => 'Warned them.',
            ])
            ->assertOk();
    }

    #[Test]
    public function a_stale_claim_releases_itself_with_no_job_having_run(): void
    {
        $first = $this->makeAdmin('First mod', Role::MODERATOR);
        $second = $this->makeAdmin('Second mod', Role::MODERATOR);
        $this->grant($second, 'reports.action');

        $report = $this->makeReport();

        $this->actingAs($first, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/claim")->assertOk();

        // A moderator who claims a report and closes their laptop must not lock it forever.
        $report->forceFill(['claimed_at' => now()->subMinutes(Report::CLAIM_MINUTES + 1)])->save();

        $this->assertFalse($report->fresh()->isClaimed());

        $this->actingAs($second, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", [
                'action' => 'warn', 'note' => 'Picked up after the claim lapsed.',
            ])
            ->assertOk();
    }

    #[Test]
    public function only_the_holder_can_release_a_claim(): void
    {
        $first = $this->makeAdmin('First mod', Role::MODERATOR);
        $second = $this->makeAdmin('Second mod', Role::MODERATOR);

        $report = $this->makeReport();

        $this->actingAs($first, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/claim")->assertOk();

        $this->actingAs($second, 'sanctum-admin')
            ->deleteJson("{$this->base}/reports/{$report->id}/claim")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NOT_YOURS');

        $this->actingAs($first, 'sanctum-admin')
            ->deleteJson("{$this->base}/reports/{$report->id}/claim")
            ->assertOk();
    }

    #[Test]
    public function resolving_a_report_drops_its_claim(): void
    {
        $mod = $this->makeAdmin('Mod', Role::MODERATOR);
        $this->grant($mod, 'reports.action');

        $report = $this->makeReport();

        $this->actingAs($mod, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/claim")->assertOk();

        $this->actingAs($mod, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", [
                'action' => 'warn', 'note' => 'Done.',
            ])->assertOk();

        // Leaving a claim on a resolved report makes the queue look busier than it is.
        $this->assertNull($report->fresh()->claimed_by);
    }

    // -------------------------------------------------------------------- C.4b

    #[Test]
    public function a_ban_longer_than_the_policy_cap_is_refused_naming_the_cap(): void
    {
        $mod = $this->makeAdmin('Capped mod', Role::MODERATOR);
        // 72-hour ceiling, exactly as C.4b describes.
        $this->grant($mod, 'reports.action', ['max_ban_hours' => 72]);
        $this->grant($mod, 'users.ban');

        $report = $this->makeReport();

        $response = $this->actingAs($mod, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", [
                'action' => 'ban_temp',
                'duration_minutes' => 30 * 24 * 60,
                'note' => 'Repeat harassment.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BAN_DURATION_CAPPED');

        // Naming the cap is the point — a bare refusal leaves them guessing.
        // The envelope carries the message at the root, not inside `error`.
        $this->assertStringContainsString('72 hours', $response->json('message'));

        // Nothing half-applied.
        $this->assertDatabaseCount('user_sanctions', 0);
        $this->assertTrue($report->fresh()->isOpen());
    }

    #[Test]
    public function a_ban_within_the_cap_is_allowed(): void
    {
        $mod = $this->makeAdmin('Capped mod', Role::MODERATOR);
        $this->grant($mod, 'reports.action', ['max_ban_hours' => 72]);

        $report = $this->makeReport();

        $this->actingAs($mod, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", [
                'action' => 'ban_temp', 'duration_minutes' => 24 * 60, 'note' => 'One day.',
            ])
            ->assertOk();
    }

    #[Test]
    public function the_tightest_cap_wins_when_two_grants_carry_one(): void
    {
        // Two people each set a limit they expected to hold; taking the looser one would
        // silently override the stricter.
        $mod = $this->makeAdmin('Twice capped', Role::MODERATOR);
        $this->grant($mod, 'reports.action', ['max_ban_hours' => 72]);
        $this->grant($mod, 'moderation.ban_temp', ['max_ban_hours' => 24]);

        $this->assertSame(24, app(BanPolicy::class)->maxBanHours($mod));
    }

    #[Test]
    public function a_super_admin_has_no_ban_cap(): void
    {
        $this->assertNull(app(BanPolicy::class)->maxBanHours($this->superAdmin));
    }

    #[Test]
    public function the_policy_endpoint_tells_the_panel_the_cap_up_front(): void
    {
        $mod = $this->makeAdmin('Capped mod', Role::MODERATOR);
        $this->grant($mod, 'reports.action', ['max_ban_hours' => 72]);

        $this->actingAs($mod, 'sanctum-admin')
            ->getJson("{$this->base}/moderation/policy")
            ->assertOk()
            ->assertJsonPath('data.ban.max_ban_hours', 72)
            ->assertJsonPath('data.ban.max_ban_minutes', 4320);
    }

    // -------------------------------------------------------------------- C.5c

    #[Test]
    public function a_user_reported_five_times_in_a_day_surfaces_as_a_recurring_issue(): void
    {
        $target = $this->makeUser();

        foreach (range(1, 5) as $i) {
            Report::create([
                'target_type' => 'user', 'target_id' => (string) $target->id,
                'reporter_id' => $this->makeUser()->id,
                'category' => 'harassment', 'priority' => 'medium', 'status' => Report::OPEN,
            ]);
        }

        // Four is below the threshold, so this one must not appear.
        $quiet = $this->makeUser();

        foreach (range(1, 4) as $i) {
            Report::create([
                'target_type' => 'user', 'target_id' => (string) $quiet->id,
                'reporter_id' => $this->makeUser()->id,
                'category' => 'spam', 'priority' => 'low', 'status' => Report::OPEN,
            ]);
        }

        $users = collect(
            $this->actingAs($this->makeAdmin('Mod', Role::MODERATOR), 'sanctum-admin')
                ->getJson("{$this->base}/moderation/recurring")
                ->assertOk()
                ->json('data.users')
        );

        $row = $users->firstWhere('id', $target->id);

        $this->assertNotNull($row);
        $this->assertSame(5, $row['reports']);
        // Five reports from one person is a feud; five from five is a pattern.
        $this->assertSame(5, $row['distinct_reporters']);
        $this->assertNull($users->firstWhere('id', $quiet->id));
    }

    #[Test]
    public function reports_outside_the_window_do_not_count(): void
    {
        $target = $this->makeUser();

        foreach (range(1, 5) as $i) {
            $report = Report::create([
                'target_type' => 'user', 'target_id' => (string) $target->id,
                'reporter_id' => $this->makeUser()->id,
                'category' => 'spam', 'priority' => 'low', 'status' => Report::OPEN,
            ]);

            $report->forceFill(['created_at' => now()->subDays(3)])->save();
        }

        $users = $this->actingAs($this->makeAdmin('Mod', Role::MODERATOR), 'sanctum-admin')
            ->getJson("{$this->base}/moderation/recurring")->json('data.users');

        $this->assertEmpty($users);
    }

    // ------------------------------------------------------------------ GFT-174

    #[Test]
    public function a_moderator_sees_their_own_actions_including_reversals(): void
    {
        $mod = $this->makeAdmin('Mod', Role::MODERATOR);
        $this->grant($mod, 'reports.action');

        $report = $this->makeReport();

        $this->actingAs($mod, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", [
                'action' => 'warn', 'note' => 'Warned them.',
            ])->assertOk();

        $action = \App\Models\ReportAction::where('admin_user_id', $mod->id)->firstOrFail();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/moderation/actions/{$action->id}/reverse", [
                'reason' => 'Overreach.',
            ])->assertOk();

        $data = $this->actingAs($mod, 'sanctum-admin')
            ->getJson("{$this->base}/moderation/my-actions")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['actions']);
        // Hiding a reversal from the person who made the call defeats the point.
        $this->assertTrue($data['actions'][0]['reversed']);
        $this->assertSame(1, $data['reversed']);
    }

    #[Test]
    public function one_moderator_does_not_see_another_moderators_actions(): void
    {
        $mine = $this->makeAdmin('Mine', Role::MODERATOR);
        $theirs = $this->makeAdmin('Theirs', Role::MODERATOR);
        $this->grant($theirs, 'reports.action');

        $report = $this->makeReport();

        $this->actingAs($theirs, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", [
                'action' => 'warn', 'note' => 'Theirs.',
            ])->assertOk();

        $this->assertCount(
            0,
            $this->actingAs($mine, 'sanctum-admin')
                ->getJson("{$this->base}/moderation/my-actions")->json('data.actions'),
        );
    }
}
