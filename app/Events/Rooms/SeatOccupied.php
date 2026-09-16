<?php

namespace App\Events\Rooms;

use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use App\Support\SocialPresenter;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A seat just got occupied — the signal every other seated client uses to open a WebRTC
 * connection to the newcomer (docs/00 §185, "Seat: a mic position"). Rides `room.{uuid}`
 * rather than a REST poll because it is the room's live floor, not a chat message.
 */
class SeatOccupied implements ShouldBroadcastNow
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
        return 'seat.occupied';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'seat_number' => $this->seat->seat_number,
            'is_vip'      => $this->seat->is_vip,
            'user'        => SocialPresenter::user($this->user),
        ];
    }
}
