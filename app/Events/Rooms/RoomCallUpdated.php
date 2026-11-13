<?php

namespace App\Events\Rooms;

use App\Models\Room;
use App\Models\RoomCall;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A private call was requested, answered, or ended — clients re-read the calls list. */
class RoomCallUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public Room $room, public RoomCall $call)
    {
    }

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'room.call';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['call_id' => $this->call->id, 'status' => $this->call->status, 'caller_uuid' => $this->call->caller?->uuid];
    }
}
