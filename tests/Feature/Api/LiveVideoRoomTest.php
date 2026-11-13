<?php

namespace Tests\Feature\Api;

use App\Models\Room;
use PHPUnit\Framework\Attributes\Test;

/**
 * Live video: a solo Video and a Video Party are rooms of a different `kind`. They reuse the
 * voice room's seats, chat, gifts and signalling, so these tests only cover what is new â€”
 * the kind, its seat counts, who may sit, the separate "own room" slot, and ending a live.
 */
class LiveVideoRoomTest extends MobileTestCase
{
    protected string $base = '/api/v1';

    #[Test]
    public function a_solo_video_has_one_seat_and_the_host_is_on_camera(): void
    {
        $this->actingAsUser($this->makeUser('Host'));

        $uuid = $this->postJson("{$this->base}/rooms", ['name' => 'Hello', 'kind' => 'video'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'video')
            ->assertJsonPath('data.seat_count', 1)
            ->assertJsonPath('data.video_enabled', true)
            ->json('data.uuid');

        $this->getJson("{$this->base}/rooms/{$uuid}/state")
            ->assertOk()
            ->assertJsonPath('data.room.kind', 'video')
            ->assertJsonPath('data.seats.0.camera_on', true)
            ->assertJsonPath('data.my.seat_number', 1);
    }

    #[Test]
    public function a_video_party_takes_four_six_or_nine_seats_only(): void
    {
        $this->actingAsUser($this->makeUser('Host'));

        $this->postJson("{$this->base}/rooms", ['name' => 'Odd', 'kind' => 'video_party', 'seat_count' => 5])
            ->assertStatus(422);

        $this->postJson("{$this->base}/rooms", ['name' => 'Nine', 'kind' => 'video_party', 'seat_count' => 9])
            ->assertCreated()
            ->assertJsonPath('data.seat_count', 9);
    }

    #[Test]
    public function a_viewer_cannot_take_a_seat_on_a_solo_video_but_can_on_a_party(): void
    {
        $host = $this->makeUser('Host');
        $this->actingAsUser($host);
        $solo = $this->postJson("{$this->base}/rooms", ['name' => 'Solo', 'kind' => 'video'])->json('data.uuid');

        $other = $this->makeUser('Guest');
        $this->actingAsUser($other);
        $this->postJson("{$this->base}/rooms/{$solo}/join")->assertOk();
        $this->postJson("{$this->base}/rooms/{$solo}/seats/1/take")->assertStatus(409)->assertJsonPath('error.code', 'NO_SEATS');

        $this->actingAsUser($this->makeUser('Party host'));
        $party = $this->postJson("{$this->base}/rooms", ['name' => 'Party', 'kind' => 'video_party', 'seat_count' => 4])->json('data.uuid');

        $this->actingAsUser($other);
        $this->postJson("{$this->base}/rooms/{$party}/join")->assertOk();
        $this->postJson("{$this->base}/rooms/{$party}/seats/2/take")->assertOk();
    }

    #[Test]
    public function a_video_does_not_use_up_the_voice_room_slot(): void
    {
        $this->actingAsUser($this->makeUser('Host'));
        $this->postJson("{$this->base}/rooms", ['name' => 'Voice'])->assertCreated();
        $video = $this->postJson("{$this->base}/rooms", ['name' => 'Video', 'kind' => 'video'])->assertCreated()->json('data.uuid');

        $this->postJson("{$this->base}/rooms", ['name' => 'Video 2', 'kind' => 'video'])
            ->assertStatus(409)
            ->assertJsonPath('error.details.uuid', $video);
    }

    #[Test]
    public function the_live_list_splits_voice_from_video(): void
    {
        $this->actingAsUser($this->makeUser('A'));
        $this->postJson("{$this->base}/rooms", ['name' => 'Voice room'])->assertCreated();
        $this->actingAsUser($this->makeUser('B'));
        $this->postJson("{$this->base}/rooms", ['name' => 'Video room', 'kind' => 'video'])->assertCreated();

        $this->actingAsUser($this->makeUser('Viewer'));
        $this->assertSame(['Video room'], collect($this->getJson("{$this->base}/rooms/live?kind=video")->json('data'))->pluck('name')->all());
        $this->assertSame(['Voice room'], collect($this->getJson("{$this->base}/rooms/live?kind=voice")->json('data'))->pluck('name')->all());
        $this->assertCount(2, $this->getJson("{$this->base}/rooms/live")->json('data'));
    }

    #[Test]
    public function only_the_host_can_end_a_live_and_it_closes_the_room(): void
    {
        $this->actingAsUser($this->makeUser('Host'));
        $uuid = $this->postJson("{$this->base}/rooms", ['name' => 'Bye', 'kind' => 'video'])->json('data.uuid');

        $this->actingAsUser($this->makeUser('Viewer'));
        $this->postJson("{$this->base}/rooms/{$uuid}/join")->assertOk();
        $this->postJson("{$this->base}/rooms/{$uuid}/end")->assertStatus(403);

        $host = Room::where('uuid', $uuid)->first()->owner;
        $this->actingAsUser($host);
        $this->postJson("{$this->base}/rooms/{$uuid}/end")
            ->assertOk()
            ->assertJsonPath('data.audience', 1);

        $this->assertSame(Room::CLOSED, Room::where('uuid', $uuid)->value('status'));
        $this->actingAsUser($this->makeUser('Late'));
        $this->postJson("{$this->base}/rooms/{$uuid}/join")->assertStatus(410);
        $this->assertCount(0, $this->getJson("{$this->base}/rooms/live?kind=video")->json('data'));
    }

    #[Test]
    public function a_voice_room_cannot_be_ended_this_way(): void
    {
        $this->actingAsUser($this->makeUser('Host'));
        $uuid = $this->postJson("{$this->base}/rooms", ['name' => 'Standing'])->json('data.uuid');

        $this->postJson("{$this->base}/rooms/{$uuid}/end")->assertStatus(400);
    }

    #[Test]
    public function a_live_ends_when_its_host_goes_quiet_but_a_quiet_viewer_only_leaves(): void
    {
        $host = $this->makeUser('Host');
        $this->actingAsUser($host);
        $uuid = $this->postJson("{$this->base}/rooms", ['name' => 'Gone', 'kind' => 'video'])->json('data.uuid');

        $viewer = $this->makeUser('Viewer');
        $this->actingAsUser($viewer);
        $this->postJson("{$this->base}/rooms/{$uuid}/join")->assertOk();

        $room = Room::where('uuid', $uuid)->firstOrFail();
        // Only the viewer goes quiet: they leave, the live carries on.
        \App\Models\RoomMember::where('room_id', $room->id)->where('user_id', $viewer->id)->update(['last_seen_at' => now()->subMinutes(5)]);
        app(\App\Domain\Rooms\RoomParticipationService::class)->evictStale();
        $this->assertSame(Room::LIVE, $room->fresh()->status);

        // The host goes quiet: the live is over.
        \App\Models\RoomMember::where('room_id', $room->id)->where('user_id', $host->id)->update(['last_seen_at' => now()->subMinutes(5)]);
        app(\App\Domain\Rooms\RoomParticipationService::class)->evictStale();
        $this->assertTrue($room->fresh()->isClosed());
        $this->assertSame('ended_by_host', $room->fresh()->close_reason);
    }
}
