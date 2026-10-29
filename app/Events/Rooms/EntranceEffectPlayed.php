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
 * The entry banner and vehicle for an arrival who is wearing one, or is VIP.
 * `arrival` is {@see \App\Domain\Store\InventoryService::arrival()} — the same shape the
 * joiner gets back as `my.arrival`, so their own phone plays exactly what the room sees.
 */
class EntranceEffectPlayed implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /** @param  array<string, mixed>  $arrival */
    public function __construct(
        public Room $room,
        public User $user,
        public array $arrival,
    ) {
    }

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'entrance.effect';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'user' => SocialPresenter::user($this->user),
            ...$this->arrival,
        ];
    }
}
