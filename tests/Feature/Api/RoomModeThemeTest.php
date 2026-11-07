<?php

namespace Tests\Feature\Api;

use App\Events\Rooms\RoomUpdated;
use App\Events\Rooms\SeatVacated;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\RoomSeatTemplate;
use App\Models\RoomTheme;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tools → Room Mode / Room Theme. A new mode lays the seats out afresh and stands
 * everybody up, but nobody leaves the room; a new theme changes only the background.
 */
class RoomModeThemeTest extends MobileTestCase
{
    protected string $base = '/api/v1';

    /** A room with its host on seat 1 and a guest on seat 3. */
    protected function busyRoom(User $host, User $guest): Room
    {
        $this->actingAsUser($host);
        $uuid = $this->postJson("{$this->base}/rooms", ['name' => 'Busy', 'seat_count' => 8])->assertCreated()->json('data.uuid');
        $room = Room::where('uuid', $uuid)->firstOrFail();

        RoomMember::create(['room_id' => $room->id, 'user_id' => $guest->id, 'role' => RoomMember::LISTENER, 'joined_at' => now(), 'is_active' => true]);
        $room->seats()->where('seat_number', 3)->update(['user_id' => $guest->id, 'occupied_at' => now()]);

        return $room;
    }

    #[Test]
    public function a_new_mode_relays_the_seats_and_stands_everyone_up_inside_the_room(): void
    {
        Event::fake([SeatVacated::class, RoomUpdated::class]);
        $host = $this->makeUser('Host');
        $guest = $this->makeUser('Guest');
        $room = $this->busyRoom($host, $guest);
        $forty = RoomSeatTemplate::create(['name' => '40', 'total_seats' => 40, 'vip_positions' => [40], 'is_active' => true]);

        $this->actingAsUser($host);
        $this->patchJson("{$this->base}/rooms/{$room->uuid}/mode", ['seat_template_id' => $forty->id])->assertOk();

        $room->refresh();
        $this->assertSame(40, $room->seat_count);
        $this->assertSame($forty->id, $room->seat_template_id);
        $this->assertSame(40, $room->seats()->count());
        $this->assertSame(0, $room->seats()->whereNotNull('user_id')->count());
        $this->assertTrue($room->seats()->where('seat_number', 40)->value('is_vip'));
        // Stood up, not thrown out.
        $this->assertSame(2, $room->activeMembers()->count());
        Event::assertDispatchedTimes(SeatVacated::class, 2);
        Event::assertDispatched(RoomUpdated::class, fn (RoomUpdated $e) => $e->reason === RoomUpdated::MODE);

        $state = $this->getJson("{$this->base}/rooms/{$room->uuid}/state")->assertOk();
        $state->assertJsonPath('data.room.seat_template_id', $forty->id);
        $this->assertCount(40, $state->json('data.seats'));
    }

    #[Test]
    public function a_new_theme_changes_the_background_and_moves_nobody(): void
    {
        Event::fake([SeatVacated::class, RoomUpdated::class]);
        $host = $this->makeUser('Host');
        $room = $this->busyRoom($host, $this->makeUser('Guest'));
        $theme = RoomTheme::create(['name' => 'Gate', 'background_url' => 'https://cdn.test/gate.png', 'is_active' => true]);

        $this->actingAsUser($host);
        $this->patchJson("{$this->base}/rooms/{$room->uuid}/theme", ['theme_id' => $theme->id])->assertOk();
        $this->getJson("{$this->base}/rooms/{$room->uuid}/state")
            ->assertJsonPath('data.room.background_url', 'https://cdn.test/gate.png')
            ->assertJsonPath('data.room.theme_id', $theme->id);

        // The "+" card: the host's own picture wins over the theme's.
        $this->patchJson("{$this->base}/rooms/{$room->uuid}/theme", ['background_url' => 'https://cdn.test/mine.jpg'])->assertOk();
        $this->getJson("{$this->base}/rooms/{$room->uuid}/state")->assertJsonPath('data.room.background_url', 'https://cdn.test/mine.jpg');

        $this->assertSame(2, $room->seats()->whereNotNull('user_id')->count());
        Event::assertNotDispatched(SeatVacated::class);
        Event::assertDispatched(RoomUpdated::class, fn (RoomUpdated $e) => $e->reason === RoomUpdated::THEME);
    }

    #[Test]
    public function only_the_host_side_may_change_mode_or_theme(): void
    {
        $host = $this->makeUser('Host');
        $guest = $this->makeUser('Guest');
        $room = $this->busyRoom($host, $guest);
        $two = RoomSeatTemplate::create(['name' => '2', 'total_seats' => 2, 'vip_positions' => [], 'is_active' => true]);
        $theme = RoomTheme::create(['name' => 'Gate', 'background_url' => 'https://cdn.test/gate.png', 'is_active' => true]);

        $this->actingAsUser($guest);
        $this->patchJson("{$this->base}/rooms/{$room->uuid}/mode", ['seat_template_id' => $two->id])->assertForbidden();
        $this->patchJson("{$this->base}/rooms/{$room->uuid}/theme", ['theme_id' => $theme->id])->assertForbidden();
        $this->assertSame(8, $room->fresh()->seat_count);
    }
}
