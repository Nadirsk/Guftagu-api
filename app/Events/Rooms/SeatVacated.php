<?php

namespace App\Events\Rooms;

use App\Models\Room;
use App\Models\User;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** The counterpart to {@see SeatOccupied} — every peer tears down that seat's WebRTC leg. */
class SeatVacated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Room $room,
        public int $seatNumber,
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
        return 'seat.vacated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'seat_number' => $this->seatNumber,
            'user_uuid'   => $this->user->uuid,
        ];
    }
}
