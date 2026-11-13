<?php

namespace App\Events\Rooms;

use App\Models\Room;
use App\Models\User;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody was put out of the room — kicked for a while, or put on its block list. The kicked person's app is still subscribed when this
 * goes out, so it closes the room and says who did it and for how long; everyone else only
 * sees the listener count drop.
 */
class MemberKicked implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param  'owner'|'admin'|'staff'  $by
     * @param  int|null  $minutes  Null is an open-ended block (a staff kick with no window).
     * @param  bool  $blocked  Put on the room's block list: out until someone lifts it.
     */
    public function __construct(
        public Room $room,
        public User $user,
        public string $by,
        public ?int $minutes,
        public int $listenerCount,
        public bool $blocked = false,
    ) {
    }

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'member.kicked';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'user_uuid'      => $this->user->uuid,
            'by'             => $this->by,
            'minutes'        => $this->minutes,
            'blocked'        => $this->blocked,
            'message'        => $this->blocked
                ? \App\Domain\Rooms\RoomKickNotice::blockedBy($this->by)
                : \App\Domain\Rooms\RoomKickNotice::kicked($this->by, $this->minutes),
            'listener_count' => $this->listenerCount,
        ];
    }
}
