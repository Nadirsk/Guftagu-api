<?php

namespace App\Events\Rooms;

use App\Models\Room;
use App\Models\User;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** D.2b — a member was promoted to, or dropped from, co-host. */
class MemberRoleChanged implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Room $room,
        public User $user,
        public string $role,
    ) {
    }

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'member.role_changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'user_uuid' => $this->user->uuid,
            'role'      => $this->role,
        ];
    }
}
