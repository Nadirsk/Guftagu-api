<?php

namespace App\Events\Rooms;

use App\Models\Room;
use App\Models\RoomMessage;
use App\Support\SocialPresenter;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** D.2d — in-room chat delivered live, the same way a seat change is. */
class RoomMessagePosted implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Room $room,
        public RoomMessage $message,
    ) {
    }

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'room.message.new';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'uuid'       => $this->message->uuid,
            'body'       => $this->message->body,
            'user'       => SocialPresenter::user($this->message->user),
            'created_at' => $this->message->created_at?->toIso8601ZuluString(),
        ];
    }
}
