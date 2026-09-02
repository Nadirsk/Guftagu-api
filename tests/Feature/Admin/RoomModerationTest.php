<?php

namespace Tests\Feature\Admin;

use App\Domain\Reports\ReportEngine;
use App\Jobs\BuildReportExport;
use App\Models\AdminUser;
use App\Models\ModerationLog;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomMember;
use App\Models\RoomSeat;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserSanction;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\RoomCatalogueSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** C.1b, C.2a–c (live-room enforcement) and GFT-107 (PDF export). */
class RoomModerationTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected AdminUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class, RoomCatalogueSeeder::class]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->superAdmin = $this->makeAdmin('Super', Role::SUPER_ADMIN);
    }

    protected function makeAdmin(string $name, string $roleKey): AdminUser
    {
        static $seq = 0;
        $seq++;

        return AdminUser::create([
            'name' => $name, 'email' => "rm{$seq}@test.local", 'password' => 'Password12345',
            'role_id' => Role::where('key', $roleKey)->value('id'), 'status' => 'active',
        ]);
    }

    protected function grant(AdminUser $admin, string $key): void
    {
        DB::table('admin_user_permission')->insert([
            'admin_user_id' => $admin->id,
            'permission_id' => DB::table('permissions')->where('key', $key)->value('id'),
            'effect'        => 'allow',
            'granted_by'    => $this->superAdmin->id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    protected function makeUser(): User
    {
        static $seq = 0;
        $seq++;

        $user = User::create([
            'guftagu_id' => 'RM'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
            'phone'      => '+9198210'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'status'     => User::STATUS_ACTIVE,
            'agora_uid'  => 950000 + $seq,
        ]);

        UserProfile::create(['user_id' => $user->id, 'display_name' => "Speaker {$seq}"]);

        return $user;
    }

    protected function makeRoom(array $attributes = [], int $seats = 5): Room
    {
        static $seq = 0;
        $seq++;

        $room = Room::create(array_merge([
            'room_code'      => 'RC'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
            'owner_id'       => $this->makeUser()->id,
            'category_id'    => RoomCategory::where('key', 'music')->value('id'),
            'name'           => "Room {$seq}",
            'seat_count'     => $seats,
            'status'         => Room::LIVE,
            'listener_count' => 20,
            'started_at'     => now()->subHour(),
        ], $attributes));

        for ($n = 1; $n <= $seats; $n++) {
            RoomSeat::create(['room_id' => $room->id, 'seat_number' => $n]);
        }

        return $room;
    }

    protected function seatUser(Room $room, int $seatNumber, User $user): RoomSeat
    {
        $seat = RoomSeat::where('room_id', $room->id)->where('seat_number', $seatNumber)->firstOrFail();
        $seat->forceFill(['user_id' => $user->id, 'occupied_at' => now()])->save();

        return $seat->fresh();
    }

    protected function joinRoom(Room $room, User $user): RoomMember
    {
        return RoomMember::create([
            'room_id' => $room->id, 'user_id' => $user->id, 'role' => 'listener',
            'joined_at' => now()->subMinutes(10), 'is_active' => true,
        ]);
    }

    // -------------------------------------------------------------------- C.1b

    #[Test]
    public function a_silent_join_creates_no_membership_or_seat_row_and_is_fully_logged(): void
    {
        $moderator = $this->makeAdmin('Mod', Role::MODERATOR); // rooms.join_silent is baseline
        $room = $this->makeRoom();

        $before = RoomMember::where('room_id', $room->id)->count();

        $data = $this->actingAs($moderator, 'sanctum-admin')
            ->postJson("{$this->base}/rooms/{$room->id}/silent-join")
            ->assertOk()
            ->json('data');

        // No participant row, no listener-count bump — nothing exists here to broadcast.
        $this->assertSame($before, RoomMember::where('room_id', $room->id)->count());
        $this->assertSame(20, $room->fresh()->listener_count);
        $this->assertFalse($data['audio']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'room.silent_join']);
        $this->assertDatabaseHas('moderation_logs', ['action' => 'silent_join', 'room_id' => $room->id]);
    }

    #[Test]
    public function silent_join_is_refused_without_the_permission(): void
    {
        $manager = $this->makeAdmin('Manager', Role::MANAGER);
        $room = $this->makeRoom();

        $this->actingAs($manager, 'sanctum-admin')
            ->postJson("{$this->base}/rooms/{$room->id}/silent-join")
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------- C.2a

    #[Test]
    public function muting_a_seat_sets_the_flag_and_writes_a_room_scoped_sanction(): void
    {
        $moderator = $this->makeAdmin('Mod', Role::MODERATOR);
        $this->grant($moderator, 'moderation.mute_user');

        $room = $this->makeRoom();
        $target = $this->makeUser();
        $this->seatUser($room, 2, $target);

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson("{$this->base}/rooms/{$room->id}/seats/2/mute", [
                'duration_minutes' => 60, 'reason' => 'Screaming into the mic.',
            ])
            ->assertOk();

        $seat = RoomSeat::where('room_id', $room->id)->where('seat_number', 2)->firstOrFail();
        $this->assertTrue($seat->is_muted_by_host);

        $this->assertDatabaseHas('user_sanctions', [
            'user_id' => $target->id, 'room_id' => $room->id, 'type' => UserSanction::MUTE,
        ]);
        $this->assertDatabaseHas('moderation_logs', ['action' => 'mute', 'room_id' => $room->id]);
    }

    #[Test]
    public function muting_an_empty_seat_is_refused(): void
    {
        $moderator = $this->makeAdmin('Mod', Role::MODERATOR);
        $this->grant($moderator, 'moderation.mute_user');

        $room = $this->makeRoom();

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson("{$this->base}/rooms/{$room->id}/seats/1/mute", ['reason' => 'Nobody is there.'])
            ->assertStatus(400);
    }

    #[Test]
    public function holding_mute_but_not_kick_permission_is_independent(): void
    {
        // C.2a, verbatim: "permissions are independent, not tiered."
        $moderator = $this->makeAdmin('Mod', Role::MODERATOR);
        $this->grant($moderator, 'moderation.mute_user');

        $room = $this->makeRoom();
        $target = $this->makeUser();
        $this->seatUser($room, 1, $target);
        $this->joinRoom($room, $target);

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson("{$this->base}/rooms/{$room->id}/seats/1/mute", ['reason' => 'Too loud.'])
            ->assertOk();

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson("{$this->base}/rooms/{$room->id}/members/{$target->id}/kick", ['reason' => 'Trying anyway.'])
            ->assertStatus(403);
    }

    #[Test]
    public function unmuting_clears_the_seat_flag_and_revokes_the_sanction(): void
    {
        $moderator = $this->makeAdmin('Mod', Role::MODERATOR);
        $this->grant($moderator, 'moderation.mute_user');

        $room = $this->makeRoom();
        $target = $this->makeUser();
        $this->seatUser($room, 1, $target);

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson("{$this->base}/rooms/{$room->id}/seats/1/mute", ['reason' => 'Loud.'])
            ->assertOk();

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson("{$this->base}/rooms/{$room->id}/seats/1/unmute")
            ->assertOk();

        $seat = RoomSeat::where('room_id', $room->id)->where('seat_number', 1)->firstOrFail();
        $this->assertFalse($seat->is_muted_by_host);

        $this->assertFalse(
            UserSanction::where('user_id', $target->id)->where('room_id', $room->id)
                ->where('type', UserSanction::MUTE)->active()->exists(),
        );
    }

    #[Test]
    public function a_lapsed_mute_stops_biting_with_no_job_having_run(): void
    {
        $target = $this->makeUser();
        $room = $this->makeRoom();

        $sanction = UserSanction::create([
            'user_id' => $target->id, 'type' => UserSanction::MUTE, 'scope' => 'room',
            'room_id' => $room->id, 'reason' => 'Loud.', 'issued_by' => $this->superAdmin->id,
            'starts_at' => now()->subHour(), 'expires_at' => now()->subMinute(), 'is_active' => true,
        ]);

        $this->assertFalse($sanction->fresh()->isInForce());
    }

    // -------------------------------------------------------------------- C.2b

    #[Test]
    public function kicking_closes_the_member_row_vacates_the_seat_and_blocks_reentry(): void
    {
        $moderator = $this->makeAdmin('Mod', Role::MODERATOR);
        $this->grant($moderator, 'moderation.kick_user');

        $room = $this->makeRoom();
        $target = $this->makeUser();
        $this->seatUser($room, 3, $target);
        $member = $this->joinRoom($room, $target);

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson("{$this->base}/rooms/{$room->id}/members/{$target->id}/kick", [
                'reentry_block_minutes' => 30, 'reason' => 'Harassing another speaker.',
            ])
            ->assertOk();

        $fresh = $member->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertNotNull($fresh->left_at);

        $seat = RoomSeat::where('room_id', $room->id)->where('seat_number', 3)->firstOrFail();
        $this->assertNull($seat->user_id);

        $this->assertTrue($room->fresh()->isBlockedForUser($target->id));
    }

    #[Test]
    public function the_reentry_block_lifts_itself_once_the_window_passes(): void
    {
        $room = $this->makeRoom();
        $target = $this->makeUser();

        UserSanction::create([
            'user_id' => $target->id, 'type' => UserSanction::ROOM_BAN, 'scope' => 'room',
            'room_id' => $room->id, 'reason' => 'Kicked.', 'issued_by' => $this->superAdmin->id,
            'starts_at' => now()->subHour(), 'expires_at' => now()->subMinute(), 'is_active' => true,
        ]);

        $this->assertFalse($room->fresh()->isBlockedForUser($target->id));
    }

    #[Test]
    public function kicking_someone_not_in_the_room_is_refused(): void
    {
        $moderator = $this->makeAdmin('Mod', Role::MODERATOR);
        $this->grant($moderator, 'moderation.kick_user');

        $room = $this->makeRoom();
        $target = $this->makeUser();

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson("{$this->base}/rooms/{$room->id}/members/{$target->id}/kick", ['reason' => 'Not here.'])
            ->assertStatus(400);
    }

    // -------------------------------------------------------------------- C.2c

    #[Test]
    public function a_warning_sends_an_in_app_notification_and_admits_no_chat_message(): void
    {
        $moderator = $this->makeAdmin('Mod', Role::MODERATOR);
        $this->grant($moderator, 'moderation.warn_user');

        $room = $this->makeRoom();
        $target = $this->makeUser();

        $data = $this->actingAs($moderator, 'sanctum-admin')
            ->postJson("{$this->base}/rooms/{$room->id}/warn", [
                'user_id' => $target->id, 'message' => 'Please keep it respectful.',
            ])
            ->assertOk()
            ->json('data');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $target->id, 'type' => 'room_warning',
        ]);
        $this->assertDatabaseHas('user_sanctions', [
            'user_id' => $target->id, 'room_id' => $room->id, 'type' => UserSanction::WARNING,
        ]);

        // Honest about the gap: there is no chat table until D.4.
        $this->assertFalse($data['chat_posted']);
    }

    // ------------------------------------------------------------------- C.1a/c

    #[Test]
    public function room_detail_is_scoped_and_refuses_an_out_of_category_room(): void
    {
        $music = RoomCategory::where('key', 'music')->value('id');
        $gaming = RoomCategory::where('key', 'gaming')->value('id');

        $moderator = $this->makeAdmin('Scoped mod', Role::MODERATOR);
        DB::table('admin_user_permission')->insert([
            'admin_user_id' => $moderator->id,
            'permission_id' => DB::table('permissions')->where('key', 'rooms.view')->value('id'),
            'effect' => 'allow', 'granted_by' => $this->superAdmin->id,
            'scope' => json_encode(['room_categories' => [$music]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $mine = $this->makeRoom(['category_id' => $music]);
        $theirs = $this->makeRoom(['category_id' => $gaming]);

        $this->actingAs($moderator, 'sanctum-admin')
            ->getJson("{$this->base}/rooms/{$mine->id}")->assertOk();

        $this->actingAs($moderator, 'sanctum-admin')
            ->getJson("{$this->base}/rooms/{$theirs->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'OUT_OF_SCOPE');
    }

    #[Test]
    public function room_detail_reports_prior_sanctions_for_an_occupant(): void
    {
        $room = $this->makeRoom();
        $target = $this->makeUser();
        $this->seatUser($room, 1, $target);

        UserSanction::create([
            'user_id' => $target->id, 'type' => UserSanction::WARNING, 'scope' => 'room',
            'room_id' => $room->id, 'reason' => 'Earlier warning.',
            'issued_by' => $this->superAdmin->id, 'starts_at' => now()->subDay(), 'is_active' => true,
        ]);

        $seats = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/rooms/{$room->id}")
            ->assertOk()
            ->json('data.seats');

        $seatOne = collect($seats)->firstWhere('seat_number', 1);
        $this->assertSame(1, $seatOne['user']['prior_sanctions_here']);
    }

    // ---------------------------------------------------------------- GFT-107

    #[Test]
    public function a_pdf_export_produces_a_real_pdf_file(): void
    {
        Storage::fake('local');
        $this->makeUser();

        $uuid = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/reports-centre/export", ['type' => 'users', 'format' => 'pdf'])
            ->assertStatus(202)
            ->json('data.uuid');

        $export = ReportExport::where('uuid', $uuid)->firstOrFail();
        (new BuildReportExport($export->id))->handle(app(ReportEngine::class));

        $export->refresh();

        $this->assertSame(ReportExport::READY, $export->status);
        $this->assertSame(1, $export->row_count);
        $this->assertStringEndsWith('.pdf', $export->file_path);

        $bytes = Storage::disk('local')->get($export->file_path);
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    #[Test]
    public function a_pdf_export_beyond_the_row_cap_is_refused_before_being_queued(): void
    {
        foreach (range(1, ReportEngine::PDF_ROW_CAP + 5) as $i) {
            $this->makeUser();
        }

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/reports-centre/export", ['type' => 'users', 'format' => 'pdf'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TOO_LARGE_FOR_PDF');

        // Refused up front — nothing was ever queued.
        $this->assertSame(0, ReportExport::count());
    }

    #[Test]
    public function csv_has_no_row_cap(): void
    {
        Storage::fake('local');

        foreach (range(1, ReportEngine::PDF_ROW_CAP + 5) as $i) {
            $this->makeUser();
        }

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/reports-centre/export", ['type' => 'users', 'format' => 'csv'])
            ->assertStatus(202);
    }

    #[Test]
    public function the_downloaded_filename_matches_the_chosen_format(): void
    {
        Storage::fake('local');
        $this->makeUser();

        $uuid = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/reports-centre/export", ['type' => 'users', 'format' => 'pdf'])
            ->json('data.uuid');

        $export = ReportExport::where('uuid', $uuid)->firstOrFail();
        (new BuildReportExport($export->id))->handle(app(ReportEngine::class));

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->get("{$this->base}/reports-centre/exports/{$uuid}/download");

        $response->assertOk();
        $this->assertStringContainsString('.pdf', $response->headers->get('content-disposition'));
    }
}
