<?php

namespace App\Events\Rooms;

use App\Models\Room;
use App\Models\User;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** D.2c — raise-hand / request-to-speak, so the host's client can render a live queue. */
class HandRaiseToggled implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Room $room,
        public User $user,
        public bool $raised,
    ) {
    }

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'hand.toggled';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'user_uuid' => $this->user->uuid,
            'raised'    => $this->raised,
        ];
    }
}
