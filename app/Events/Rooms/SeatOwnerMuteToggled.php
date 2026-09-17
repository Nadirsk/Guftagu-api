<?php

namespace App\Events\Rooms;

use App\Models\Room;
use App\Models\RoomSeat;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * D.2b — the room owner/co-host muted or unmuted a seat. Distinct from {@see MicToggled}
 * (self) and the moderator sanction behind `is_muted_by_host` — this is `is_muted_by_owner`.
 */
class SeatOwnerMuteToggled implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Room $room,
        public RoomSeat $seat,
    ) {
    }

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'seat.owner_muted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'seat_number' => $this->seat->seat_number,
            'muted'       => $this->seat->is_muted_by_owner,
        ];
    }
}
