<?php

namespace App\Events\Rooms;

use App\Models\Room;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The host changed the room itself — its Room Mode (the seat layout, every seat emptied)
 * or its Room Theme (the background, nobody moved). Clients re-read `state` on it.
 */
class RoomUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public const MODE = 'mode';

    public const THEME = 'theme';

    public function __construct(
        public Room $room,
        public string $reason,
    ) {
    }

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'room.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'reason'           => $this->reason,
            'seat_count'       => $this->room->seat_count,
            'seat_template_id' => $this->room->seat_template_id,
            'theme_id'         => $this->room->theme_id,
            'background_url'   => $this->room->background_url ?? $this->room->theme?->background_url,
        ];
    }
}
