<?php

namespace App\Events\Rooms;

use App\Models\Room;
use App\Models\RoomRedEnvelope;
use App\Support\SocialPresenter;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A red packet was dropped in the room — everyone inside sees it appear. */
class EnvelopeCreated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public Room $room, public RoomRedEnvelope $envelope)
    {
    }

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'envelope.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'envelope' => [
                'uuid'            => $this->envelope->uuid,
                'sender'          => SocialPresenter::user($this->envelope->sender),
                'total_coins'     => $this->envelope->total_coins,
                'total_count'     => $this->envelope->total_count,
                'remaining_count' => $this->envelope->remaining_count,
            ],
        ];
    }
}
