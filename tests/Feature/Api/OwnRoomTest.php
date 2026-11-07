<?php

namespace Tests\Feature\Api;

use App\Models\Room;
use PHPUnit\Framework\Attributes\Test;

/**
 * Party → Create My Room: the create screen comes once. After that the user has a room,
 * `rooms/mine` names it so the app joins it, and a second create is refused.
 */
class OwnRoomTest extends MobileTestCase
{
    protected string $base = '/api/v1';

    #[Test]
    public function the_first_create_makes_the_room_and_mine_finds_it(): void
    {
        $this->actingAsUser($this->makeUser('Host'));

        $this->getJson("{$this->base}/rooms/mine")->assertOk()->assertJsonPath('data.room', null);

        $uuid = $this->postJson("{$this->base}/rooms", ['name' => 'My place'])->assertCreated()->json('data.uuid');

        $this->getJson("{$this->base}/rooms/mine")
            ->assertOk()
            ->assertJsonPath('data.room.uuid', $uuid)
            ->assertJsonPath('data.room.name', 'My place');
    }

    #[Test]
    public function a_second_create_is_refused_and_names_the_room_to_join(): void
    {
        $this->actingAsUser($this->makeUser('Host'));
        $uuid = $this->postJson("{$this->base}/rooms", ['name' => 'First'])->assertCreated()->json('data.uuid');

        $this->postJson("{$this->base}/rooms", ['name' => 'Second'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ROOM_EXISTS')
            ->assertJsonPath('error.details.uuid', $uuid);
    }

    #[Test]
    public function a_room_closed_by_moderation_frees_the_user_to_create_again(): void
    {
        $me = $this->actingAsUser($this->makeUser('Host'));
        $uuid = $this->postJson("{$this->base}/rooms", ['name' => 'Gone'])->assertCreated()->json('data.uuid');
        Room::where('uuid', $uuid)->update(['status' => Room::FORCE_CLOSED]);

        $this->getJson("{$this->base}/rooms/mine")->assertJsonPath('data.room', null);
        $this->postJson("{$this->base}/rooms", ['name' => 'Again'])->assertCreated();
        $this->assertSame(2, Room::where('owner_id', $me->id)->count());
    }
}
