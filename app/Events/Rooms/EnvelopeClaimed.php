<?php

namespace App\Events\Rooms;

use App\Models\Room;
use App\Models\RoomRedEnvelope;
use App\Models\User;
use App\Support\SocialPresenter;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Somebody opened the packet — the others' copy disappears once the last share is gone. */
class EnvelopeClaimed implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public Room $room, public RoomRedEnvelope $envelope, public User $claimer, public int $amount)
    {
    }

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'envelope.claimed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'envelope_uuid'   => $this->envelope->uuid,
            'claimer'         => SocialPresenter::user($this->claimer),
            'amount'          => $this->amount,
            'remaining_count' => $this->envelope->remaining_count,
        ];
    }
}
