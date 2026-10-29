<?php

namespace App\Events\Rooms;

use App\Models\Room;
use App\Models\User;
use App\Support\SocialPresenter;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody walked in — the "X entered the room" chat line. Only on an arrival, never on a
 * reconnect by someone already inside, and never for an admin's silent join.
 * If they are wearing anything, {@see EntranceEffectPlayed} follows with the banner.
 */
class MemberJoined implements ShouldBroadcastNow
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
        return 'member.joined';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'user'           => SocialPresenter::user($this->user),
            'listener_count' => $this->listenerCount,
        ];
    }
}
