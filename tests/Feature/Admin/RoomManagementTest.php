<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\ModerationLog;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomMember;
use App\Models\RoomSeat;
use App\Models\RoomTheme;
use App\Models\User;
use App\Models\UserProfile;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\RoomCatalogueSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Epic A.4 acceptance criteria. */
class RoomManagementTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected AdminUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class, RoomCatalogueSeeder::class]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->superAdmin = $this->makeAdmin(Role::SUPER_ADMIN);
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

    protected function makeUser(): User
    {
        static $seq = 0;
        $seq++;

        $user = User::create([
            'guftagu_id' => 'GF'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
            'phone'      => '+9198202'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'status'     => User::STATUS_ACTIVE,
            'agora_uid'  => 600000 + $seq,
        ]);

        UserProfile::create(['user_id' => $user->id, 'display_name' => "Host {$seq}"]);

        return $user;
    }

    protected function makeRoom(array $attributes = [], int $seats = 5): Room
    {
        static $seq = 0;
        $seq++;

        $room = Room::create(array_merge([
            'room_code'      => 'RM'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
            'owner_id'       => $this->makeUser()->id,
            'category_id'    => RoomCategory::where('key', 'music')->value('id'),
            'name'           => "Room {$seq}",
            'seat_count'     => $seats,
            'status'         => Room::LIVE,
            'listener_count' => 30,
            'started_at'     => now()->subHour(),
        ], $attributes));

        for ($n = 1; $n <= $seats; $n++) {
            RoomSeat::create(['room_id' => $room->id, 'seat_number' => $n]);
        }

        return $room;
    }

    // -------------------------------------------------------------------- A.4a

    #[Test]
    public function the_live_view_filters_by_category_and_shows_only_live_rooms(): void
    {
        $music = RoomCategory::where('key', 'music')->value('id');
        $gaming = RoomCategory::where('key', 'gaming')->value('id');

        $this->makeRoom(['category_id' => $music, 'name' => 'Ghazals']);
        $this->makeRoom(['category_id' => $gaming, 'name' => 'BGMI']);
        $this->makeRoom(['category_id' => $music, 'name' => 'Closed one', 'status' => Room::FORCE_CLOSED]);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson($this->base.'/rooms/live?category='.$music)
            ->assertOk();

        $names = collect($response->json('data.rooms'))->pluck('name')->all();

        $this->assertSame(['Ghazals'], $names, 'Only live rooms in that category.');

        // The response is honest that these counts are not live yet.
        $this->assertFalse($response->json('data.realtime.available'));
    }

    #[Test]
    public function the_room_list_orders_pinned_first_then_by_listeners(): void
    {
        $this->makeRoom(['name' => 'Quiet', 'listener_count' => 5]);
        $this->makeRoom(['name' => 'Busy', 'listener_count' => 900]);
        $this->makeRoom(['name' => 'Pinned', 'listener_count' => 1, 'is_pinned' => true]);

        $names = collect(
            $this->actingAs($this->superAdmin, 'sanctum-admin')
                ->getJson($this->base.'/rooms')->assertOk()->json('data')
        )->pluck('name')->all();

        $this->assertSame(['Pinned', 'Busy', 'Quiet'], $names);
    }

    // -------------------------------------------------------------------- A.4b

    #[Test]
    public function a_feature_window_lapses_without_any_job_running(): void
    {
        $room = $this->makeRoom();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/rooms/{$room->id}/feature", [
                'featured' => true,
                'until'    => now()->addDay()->toIso8601String(),
            ])->assertOk();

        $this->assertTrue($room->fresh()->isCurrentlyFeatured());
        $this->assertSame(1, Room::query()->featured()->count());

        // Wind the window into the past. Nothing else runs — no scheduler, no job.
        $room->forceFill(['featured_until' => now()->subMinute()])->save();

        $this->assertFalse($room->fresh()->isCurrentlyFeatured(), 'An expired window must stop counting.');
        $this->assertSame(0, Room::query()->featured()->count());

        // And the list reports the effective state, not the raw column.
        $row = collect(
            $this->actingAs($this->superAdmin, 'sanctum-admin')
                ->getJson($this->base.'/rooms')->json('data')
        )->firstWhere('id', $room->id);

        $this->assertFalse($row['is_featured'], 'The panel must see the effective state.');
        $this->assertTrue($row['featured_flag'], 'The stored flag is still true — both are reported.');
    }

    #[Test]
    public function a_feature_window_in_the_past_is_rejected(): void
    {
        $room = $this->makeRoom();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/rooms/{$room->id}/feature", [
                'featured' => true,
                'until'    => now()->subDay()->toIso8601String(),
            ])->assertStatus(422);
    }

    #[Test]
    public function a_closed_room_cannot_be_featured(): void
    {
        $room = $this->makeRoom(['status' => Room::FORCE_CLOSED]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/rooms/{$room->id}/feature", ['featured' => true])
            ->assertStatus(400);
    }

    // -------------------------------------------------------------------- A.4c

    #[Test]
    public function force_closing_evicts_everyone_and_lands_in_both_logs(): void
    {
        $room = $this->makeRoom([], seats: 5);

        // Seat two people and mark them present.
        foreach ([1, 2] as $seatNumber) {
            $user = $this->makeUser();
            RoomSeat::where('room_id', $room->id)->where('seat_number', $seatNumber)
                ->update(['user_id' => $user->id, 'occupied_at' => now()->subMinutes(20)]);
            RoomMember::create([
                'room_id' => $room->id, 'user_id' => $user->id,
                'role' => 'speaker', 'joined_at' => now()->subMinutes(20), 'is_active' => true,
            ]);
        }

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/rooms/{$room->id}/close", ['reason' => 'Hate speech from the host.'])
            ->assertOk()
            ->assertJsonPath('data.status', Room::FORCE_CLOSED);

        $room->refresh();

        $this->assertSame(Room::FORCE_CLOSED, $room->status);
        $this->assertSame($this->superAdmin->id, $room->closed_by);
        $this->assertSame('Hate speech from the host.', $room->close_reason);
        $this->assertSame(0, $room->listener_count);
        $this->assertFalse($room->is_featured, 'A closed room must not keep a featured slot.');

        $this->assertSame(0, RoomMember::where('room_id', $room->id)->where('is_active', true)->count());
        $this->assertSame(0, RoomSeat::where('room_id', $room->id)->whereNotNull('user_id')->count());

        // A.4c wants it in BOTH logs, with the admin's identity.
        $this->assertTrue(
            AuditLog::where('action', 'room.force_close')->where('admin_user_id', $this->superAdmin->id)->exists(),
            'Missing from audit_logs.'
        );
        $this->assertTrue(
            ModerationLog::where('action', 'room_close')->where('admin_user_id', $this->superAdmin->id)->exists(),
            'Missing from moderation_logs.'
        );
    }

    #[Test]
    public function force_closing_without_a_reason_is_rejected(): void
    {
        $room = $this->makeRoom();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/rooms/{$room->id}/close", [])
            ->assertStatus(422);

        $this->assertSame(Room::LIVE, $room->fresh()->status);
    }

    #[Test]
    public function a_room_cannot_be_closed_twice(): void
    {
        $room = $this->makeRoom(['status' => Room::FORCE_CLOSED]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/rooms/{$room->id}/close", ['reason' => 'Again.'])
            ->assertStatus(400);
    }

    #[Test]
    public function without_the_permission_a_direct_close_call_is_refused(): void
    {
        // A.4c is explicit: hiding the button is not the control.
        $moderator = $this->makeAdmin(Role::MODERATOR);
        $room = $this->makeRoom();

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson($this->base."/rooms/{$room->id}/close", ['reason' => 'Trying it on.'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');

        $this->assertSame(Room::LIVE, $room->fresh()->status);
    }

    #[Test]
    public function a_moderator_can_still_monitor_rooms(): void
    {
        // The moderator baseline holds rooms.view and rooms.monitor_live by design.
        $moderator = $this->makeAdmin(Role::MODERATOR);
        $this->makeRoom();

        $this->actingAs($moderator, 'sanctum-admin')->getJson($this->base.'/rooms')->assertOk();
        $this->actingAs($moderator, 'sanctum-admin')->getJson($this->base.'/rooms/live')->assertOk();
    }

    #[Test]
    public function locking_an_occupied_seat_turns_the_occupant_out(): void
    {
        $room = $this->makeRoom([], seats: 5);
        $user = $this->makeUser();

        RoomSeat::where('room_id', $room->id)->where('seat_number', 3)
            ->update(['user_id' => $user->id, 'occupied_at' => now()]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/rooms/{$room->id}/seats/3/lock", ['locked' => true])
            ->assertOk();

        $seat = RoomSeat::where('room_id', $room->id)->where('seat_number', 3)->first();

        $this->assertTrue($seat->is_locked);
        $this->assertNull($seat->user_id, 'Nobody may remain seated on a locked seat.');
        $this->assertTrue(ModerationLog::where('action', 'seat_lock')->exists());
    }

    #[Test]
    public function locking_a_seat_that_does_not_exist_is_a_404(): void
    {
        $room = $this->makeRoom([], seats: 5);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/rooms/{$room->id}/seats/99/lock", ['locked' => true])
            ->assertStatus(404);
    }

    #[Test]
    public function a_seat_can_be_marked_and_unmarked_vip_independently_of_occupancy(): void
    {
        $room = $this->makeRoom([], seats: 5);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/rooms/{$room->id}/seats/1/vip", ['vip' => true])
            ->assertOk();

        $seat = RoomSeat::where('room_id', $room->id)->where('seat_number', 1)->first();
        $this->assertTrue($seat->is_vip);
        $this->assertTrue(AuditLog::where('action', 'room.seat_vip')->exists());
        $this->assertTrue(ModerationLog::where('action', 'room.seat_vip')->exists());

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/rooms/{$room->id}/seats/1/vip", ['vip' => false])
            ->assertOk();

        $this->assertFalse($seat->fresh()->is_vip);
    }

    #[Test]
    public function a_vip_seat_toggle_needs_its_own_permission(): void
    {
        $moderator = $this->makeAdmin(Role::MODERATOR);
        $room = $this->makeRoom([], seats: 5);

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson($this->base."/rooms/{$room->id}/seats/1/vip", ['vip' => true])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    #[Test]
    public function marking_vip_on_a_seat_that_does_not_exist_is_a_404(): void
    {
        $room = $this->makeRoom([], seats: 5);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/rooms/{$room->id}/seats/99/vip", ['vip' => true])
            ->assertStatus(404);
    }

    #[Test]
    public function assigning_a_seat_template_applies_its_vip_positions_to_the_room(): void
    {
        $room = $this->makeRoom([], seats: 8);
        $template = \App\Models\RoomSeatTemplate::create([
            'name' => '8 seats — 2 VIP', 'total_seats' => 8, 'vip_positions' => [1, 2],
        ]);

        // Seat 3 starts VIP from an earlier manual toggle — applying the template must
        // still turn it off, since the template is the new decided layout.
        RoomSeat::where('room_id', $room->id)->where('seat_number', 3)->update(['is_vip' => true]);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson($this->base."/rooms/{$room->id}/seat-template", ['seat_template_id' => $template->id])
            ->assertOk();

        $this->assertSame($template->id, $response->json('data.seat_template_id'));
        $this->assertSame($template->id, $room->fresh()->seat_template_id);

        $seats = RoomSeat::where('room_id', $room->id)->orderBy('seat_number')->pluck('is_vip', 'seat_number');
        $this->assertTrue($seats[1]);
        $this->assertTrue($seats[2]);
        $this->assertFalse($seats[3], 'The template is the new decided layout — it must win over the stale manual mark.');
        $this->assertFalse($seats[4]);

        $this->assertTrue(AuditLog::where('action', 'room.seat_template_assign')->exists());
    }

    #[Test]
    public function a_template_position_past_the_rooms_own_seat_count_is_ignored_not_rejected(): void
    {
        $room = $this->makeRoom([], seats: 5);
        $template = \App\Models\RoomSeatTemplate::create([
            'name' => '12 seats — 2 VIP', 'total_seats' => 12, 'vip_positions' => [1, 9],
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson($this->base."/rooms/{$room->id}/seat-template", ['seat_template_id' => $template->id])
            ->assertOk();

        $this->assertTrue(RoomSeat::where('room_id', $room->id)->where('seat_number', 1)->value('is_vip'));
        // Position 9 simply doesn't exist on a 5-seat room — nothing to update, no error.
        $this->assertSame(5, RoomSeat::where('room_id', $room->id)->count());
    }

    #[Test]
    public function clearing_a_seat_template_unlinks_it_without_touching_existing_vip_flags(): void
    {
        $room = $this->makeRoom([], seats: 5);
        $template = \App\Models\RoomSeatTemplate::create([
            'name' => 'Five with VIP at 1', 'total_seats' => 5, 'vip_positions' => [1],
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson($this->base."/rooms/{$room->id}/seat-template", ['seat_template_id' => $template->id])
            ->assertOk();

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson($this->base."/rooms/{$room->id}/seat-template", ['seat_template_id' => null])
            ->assertOk();

        $this->assertNull($response->json('data.seat_template_id'));
        $this->assertNull($room->fresh()->seat_template_id);
        $this->assertTrue(
            RoomSeat::where('room_id', $room->id)->where('seat_number', 1)->value('is_vip'),
            'Unlinking must not silently strip a VIP flag the template put there.',
        );
    }

    #[Test]
    public function assigning_an_unknown_seat_template_is_a_validation_error(): void
    {
        $room = $this->makeRoom([], seats: 5);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson($this->base."/rooms/{$room->id}/seat-template", ['seat_template_id' => 999999])
            ->assertStatus(422);
    }

    #[Test]
    public function assigning_a_seat_template_needs_its_own_permission(): void
    {
        $moderator = $this->makeAdmin(Role::MODERATOR);
        $room = $this->makeRoom([], seats: 5);
        $template = \App\Models\RoomSeatTemplate::create(['name' => 'X', 'total_seats' => 5]);

        $this->actingAs($moderator, 'sanctum-admin')
            ->patchJson($this->base."/rooms/{$room->id}/seat-template", ['seat_template_id' => $template->id])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    // -------------------------------------------------------- seat templates

    #[Test]
    public function a_seat_template_can_be_created_with_vip_positions(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/room-seat-templates', [
                'name' => '12 seats — 2 VIP', 'total_seats' => 12, 'vip_positions' => [1, 2],
            ])
            ->assertStatus(201);

        $this->assertSame(2, $response->json('data.vip_seats'));
        $this->assertTrue(AuditLog::where('action', 'room_seat_template.create')->exists());
    }

    #[Test]
    public function a_vip_position_past_the_seat_total_is_rejected(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/room-seat-templates', [
                'name' => 'Broken', 'total_seats' => 8, 'vip_positions' => [1, 9],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function shrinking_total_seats_below_a_stored_vip_position_is_refused(): void
    {
        $template = \App\Models\RoomSeatTemplate::create([
            'name' => 'Ten with VIP at 9', 'total_seats' => 10, 'vip_positions' => [9],
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson($this->base."/room-seat-templates/{$template->id}", ['total_seats' => 5])
            ->assertStatus(422);

        $this->assertSame('VALIDATION_ERROR', $response->json('error.code'));
        $this->assertSame(10, $template->fresh()->total_seats, 'The bad update must not have applied.');
    }

    #[Test]
    public function a_seat_template_needs_theme_manage_to_write(): void
    {
        $manager = $this->makeAdmin(Role::MANAGER);

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson($this->base.'/room-seat-templates', ['name' => 'X', 'total_seats' => 8])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    // -------------------------------------------------------------------- A.4d

    #[Test]
    public function categories_can_be_created_and_are_bilingual(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/room-categories', [
                'key' => 'poetry', 'name_en' => 'Poetry', 'name_hi' => 'कविता', 'sort_order' => 90,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('room_categories', ['key' => 'poetry', 'name_hi' => 'कविता']);
        $this->assertTrue(AuditLog::where('action', 'room_category.create')->exists());
    }

    #[Test]
    public function a_category_in_use_cannot_be_deleted(): void
    {
        $category = RoomCategory::where('key', 'music')->first();
        $this->makeRoom(['category_id' => $category->id]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->deleteJson($this->base."/room-categories/{$category->id}")
            ->assertStatus(400)
            ->assertJsonPath('error.details.room_count', 1);

        $this->assertDatabaseHas('room_categories', ['id' => $category->id]);
    }

    #[Test]
    public function an_unused_category_can_be_deleted(): void
    {
        $category = RoomCategory::create(['key' => 'temp', 'name_en' => 'Temp']);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->deleteJson($this->base."/room-categories/{$category->id}")
            ->assertOk();

        $this->assertDatabaseMissing('room_categories', ['id' => $category->id]);
    }

    #[Test]
    public function a_premium_theme_stores_its_vip_gate(): void
    {
        // Tiers exist since A.6, and the column now has a foreign key — so the gate has to
        // be a real tier id. It is NOT the VIP level, which is what this test used to send
        // back when the column was unconstrained.
        $this->seed(\Database\Seeders\VipTierSeeder::class);

        $tierId = \App\Models\VipTier::where('level', 3)->value('id');

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/room-themes', [
                'name' => 'Palace', 'is_premium' => true, 'required_vip_tier_id' => $tierId, 'coin_price' => 9000,
            ])
            ->assertStatus(201);

        $theme = RoomTheme::where('name', 'Palace')->first();

        $this->assertTrue($theme->is_premium);
        $this->assertSame($tierId, $theme->required_vip_tier_id);
        $this->assertSame(9000, $theme->coin_price);
    }

    #[Test]
    public function a_theme_gate_pointing_at_a_missing_tier_is_a_field_error_not_a_crash(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/room-themes', [
                'name' => 'Nowhere', 'is_premium' => true, 'required_vip_tier_id' => 9999,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function reading_the_catalogue_needs_only_rooms_view_but_changing_it_needs_theme_manage(): void
    {
        $moderator = $this->makeAdmin(Role::MODERATOR);

        // Every screen showing a room needs its category name, so reading is cheap.
        $this->actingAs($moderator, 'sanctum-admin')
            ->getJson($this->base.'/room-categories')->assertOk();

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson($this->base.'/room-categories', ['key' => 'nope', 'name_en' => 'Nope'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    #[Test]
    public function the_catalogue_seeder_is_idempotent(): void
    {
        $before = RoomCategory::count();

        $this->seed(RoomCatalogueSeeder::class);

        $this->assertSame($before, RoomCategory::count(), 'Re-seeding must not duplicate categories.');
    }
}
