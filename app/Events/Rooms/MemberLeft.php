<?php

namespace App\Events\Rooms;

use App\Models\Room;
use App\Models\User;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** The counterpart to {@see MemberJoined} — only the listener count moves on screen. */
class MemberLeft implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Room $room,
        public User $user,
        public int $listenerCount,
    ) {
    }

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'member.left';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'user_uuid'      => $this->user->uuid,
            'listener_count' => $this->listenerCount,
        ];
    }
}
