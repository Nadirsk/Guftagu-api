<?php

namespace App\Events\Rooms;

use App\Models\Room;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** D.2d — the room's pinned announcement changed. */
class RoomAnnouncementUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Room $room,
    ) {
    }

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'room.announcement_changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['announcement' => $this->room->announcement];
    }
}
