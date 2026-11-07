<?php

namespace Tests\Feature\Api;

use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomMember;
use App\Models\RoomMessage;
use App\Models\RoomSeat;
use Database\Seeders\RoomCatalogueSeeder;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mazag parity (observed on a real device): chat is only what was said since you walked in,
 * Exit empties your seat, and coming back is a fresh arrival — no old chat, no seat.
 */
class RoomRejoinTest extends MobileTestCase
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
            'room_code'   => 'RJ000001',
            'owner_id'    => $this->makeUser('Owner')->id,
            'category_id' => RoomCategory::where('key', 'music')->value('id'),
            'name'        => 'Rejoin',
            'seat_count'  => 5,
            'status'      => Room::LIVE,
            'started_at'  => now()->subHour(),
        ]);

        for ($n = 1; $n <= 5; $n++) {
            RoomSeat::create(['room_id' => $room->id, 'seat_number' => $n]);
        }

        return $room;
    }

    protected function say(Room $room, int $userId, string $body, string $when): void
    {
        $message = RoomMessage::create(['room_id' => $room->id, 'user_id' => $userId, 'body' => $body]);
        $message->forceFill(['created_at' => $when])->save();
    }

    /** @return list<string> */
    protected function bodies(Room $room): array
    {
        return collect($this->getJson("{$this->base}/rooms/{$room->uuid}/messages")->assertOk()->json('data'))
            ->pluck('body')
            ->all();
    }

    #[Test]
    public function chat_from_before_you_joined_is_never_returned(): void
    {
        $room = $this->makeRoom();
        $talker = $this->makeUser('Talker');
        $this->say($room, $talker->id, 'old', now()->subMinutes(10)->toDateTimeString());

        $me = $this->actingAsUser($this->makeUser('Late'));
        $this->postJson("{$this->base}/rooms/{$room->uuid}/join")->assertOk();

        $this->assertSame([], $this->bodies($room));

        $this->say($room, $talker->id, 'new', now()->addSecond()->toDateTimeString());
        $this->assertSame(['new'], $this->bodies($room));
    }

    #[Test]
    public function someone_outside_the_room_gets_no_chat(): void
    {
        $room = $this->makeRoom();
        $this->say($room, $room->owner_id, 'hello', now()->toDateTimeString());

        $this->actingAsUser($this->makeUser('Outsider'));

        $this->assertSame([], $this->bodies($room));
    }

    #[Test]
    public function exiting_empties_the_seat_and_coming_back_is_a_fresh_arrival(): void
    {
        $room = $this->makeRoom();
        $talker = $this->makeUser('Talker');
        $me = $this->actingAsUser($this->makeUser('Seated'));

        $this->postJson("{$this->base}/rooms/{$room->uuid}/join")->assertOk();
        $this->postJson("{$this->base}/rooms/{$room->uuid}/seats/2/take")->assertOk()
            ->assertJsonPath('data.my.seat_number', 2);
        $this->say($room, $talker->id, 'while you sat', now()->addSecond()->toDateTimeString());
        $this->assertSame(['while you sat'], $this->bodies($room));

        $this->postJson("{$this->base}/rooms/{$room->uuid}/leave")->assertOk();

        $this->assertNull(RoomSeat::where('room_id', $room->id)->where('seat_number', 2)->value('user_id'));
        $this->assertFalse(RoomMember::where('room_id', $room->id)->where('user_id', $me->id)->value('is_active'));

        // Back in: a new arrival, not seated, and the earlier chat stays behind.
        RoomMember::where('room_id', $room->id)->where('user_id', $me->id)->update(['left_at' => now()->subSecond()]);
        $this->travel(5)->seconds();
        $this->postJson("{$this->base}/rooms/{$room->uuid}/join")->assertOk()
            ->assertJsonPath('data.my.seat_number', null);

        $this->assertSame([], $this->bodies($room));
    }

    #[Test]
    public function a_reconnect_without_exiting_keeps_the_seat_and_the_chat_window(): void
    {
        $room = $this->makeRoom();
        $talker = $this->makeUser('Talker');
        $this->actingAsUser($this->makeUser('Minimiser'));

        $this->postJson("{$this->base}/rooms/{$room->uuid}/join")->assertOk();
        $this->postJson("{$this->base}/rooms/{$room->uuid}/seats/3/take")->assertOk();
        $this->say($room, $talker->id, 'hi', now()->addSecond()->toDateTimeString());

        // Minimise then reopen = a second join call while still a member.
        $this->postJson("{$this->base}/rooms/{$room->uuid}/join")->assertOk()
            ->assertJsonPath('data.my.seat_number', 3);

        $this->assertSame(['hi'], $this->bodies($room));
    }
}
