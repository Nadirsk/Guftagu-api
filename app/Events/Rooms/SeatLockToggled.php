<?php

namespace App\Events\Rooms;

use App\Models\Room;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** D.2b — the room owner/co-host locked or unlocked a seat. */
class SeatLockToggled implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Room $room,
        public int $seatNumber,
        public bool $locked,
    ) {
    }

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'seat.locked';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'seat_number' => $this->seatNumber,
            'locked'      => $this->locked,
        ];
    }
}
