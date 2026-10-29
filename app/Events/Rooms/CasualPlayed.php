<?php

namespace App\Events\Rooms;

use App\Models\Room;
use App\Models\User;
use App\Support\SocialPresenter;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** `casual.played` — a dice / rock-paper-scissors / slot emoji, with the result the server drew. */
class CasualPlayed implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /** @param array<int, int> $result dice: [1-6]; mora: [0-2]; machine: three digits 0-9 */
    public function __construct(
        public Room $room,
        public User $user,
        public string $type,
        public array $result,
    ) {
    }

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'casual.played';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type'   => $this->type,
            'result' => $this->result,
            'user'   => SocialPresenter::user($this->user),
        ];
    }
}
