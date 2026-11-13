<?php

namespace Tests\Feature\Api;

use App\Domain\Rooms\RoomParticipationService;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomMember;
use App\Models\RoomSeat;
use Database\Seeders\RoomCatalogueSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Voice-room behaviour: closing the app takes you off the mic and out of the room. Nothing tells
 * the server an app was killed, so a heartbeat (the `/state` poll) gone quiet for 90 s is the signal.
 */
class RoomStaleEvictionTest extends MobileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoomCatalogueSeeder::class);
        Event::fake();
    }

    protected function makeRoom(): Room
    {
        $room = Room::create([
            'room_code'   => 'SE000001',
            'owner_id'    => $this->makeUser('Owner')->id,
            'category_id' => RoomCategory::where('key', 'music')->value('id'),
            'name'        => 'Stale',
            'seat_count'  => 5,
            'status'      => Room::LIVE,
            'started_at'  => now()->subHour(),
        ]);

        for ($n = 1; $n <= 5; $n++) {
            RoomSeat::create(['room_id' => $room->id, 'seat_number' => $n]);
        }

        return $room;
    }

    /** Someone sitting on seat 1 whose last heartbeat was `$secondsAgo` seconds ago. */
    protected function seated(Room $room, string $name, int $secondsAgo): RoomMember
    {
        $user = $this->makeUser($name);
        $room->seats()->where('seat_number', 1)->update(['user_id' => $user->id, 'occupied_at' => now()]);

        return RoomMember::create([
            'room_id' => $room->id, 'user_id' => $user->id, 'role' => 'listener',
            'joined_at' => now()->subMinutes(10), 'last_seen_at' => now()->subSeconds($secondsAgo), 'is_active' => true,
        ]);
    }

    #[Test]
    public function a_quiet_member_leaves_the_room_and_their_seat(): void
    {
        $room = $this->makeRoom();
        $gone = $this->seated($room, 'Closed the app', 200);

        $this->assertSame(1, app(RoomParticipationService::class)->evictStale());

        $this->assertFalse($gone->fresh()->is_active);
        $this->assertNotNull($gone->fresh()->left_at);
        $this->assertNull($room->seats()->where('seat_number', 1)->value('user_id'));
    }

    #[Test]
    public function someone_still_heartbeating_stays(): void
    {
        $room = $this->makeRoom();
        $here = $this->seated($room, 'Still here', 20);

        $this->assertSame(0, app(RoomParticipationService::class)->evictStale());

        $this->assertTrue($here->fresh()->is_active);
        $this->assertSame($here->user_id, $room->seats()->where('seat_number', 1)->value('user_id'));
    }

    #[Test]
    public function the_state_poll_clears_ghosts_for_everyone_else_but_never_the_caller(): void
    {
        Cache::flush();
        $room = $this->makeRoom();
        $ghost = $this->seated($room, 'Ghost', 300);

        // The caller's own heartbeat is long overdue too — hitting /state must refresh it first.
        $me = $this->actingAsUser($this->makeUser('Me'));
        $mine = RoomMember::create([
            'room_id' => $room->id, 'user_id' => $me->id, 'role' => 'listener',
            'joined_at' => now()->subMinutes(10), 'last_seen_at' => now()->subSeconds(300), 'is_active' => true,
        ]);

        $json = $this->getJson("{$this->base}/rooms/{$room->uuid}/state")->assertOk()->json('data');

        $this->assertFalse($ghost->fresh()->is_active);
        $this->assertTrue($mine->fresh()->is_active);
        $seat = collect($json['seats'])->firstWhere('seat_number', 1);
        $this->assertNotNull($seat, 'seat 1 must be in the snapshot');
        $this->assertNull($seat['user'], 'the ghost must be off the mic in the snapshot others receive');
    }
}
