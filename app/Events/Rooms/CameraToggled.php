<?php

namespace App\Events\Rooms;

use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** docs/03 §178 — `PATCH /rooms/{uuid}/camera`, D.5b. Seated-only, like the mic. */
class CameraToggled implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Room $room,
        public RoomSeat $seat,
        public User $user,
    ) {
    }

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'camera.toggled';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'seat_number' => $this->seat->seat_number,
            'user_uuid'   => $this->user->uuid,
            'camera_on'   => $this->seat->is_camera_on,
        ];
    }
}
