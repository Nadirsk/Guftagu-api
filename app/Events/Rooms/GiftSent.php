<?php

namespace App\Events\Rooms;

use App\Models\Gift;
use App\Models\GiftTransaction;
use App\Models\Room;
use App\Models\User;
use App\Support\SocialPresenter;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** D.6a — a gift landed in the room everyone else needs to render the entrance effect for. */
class GiftSent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Room $room,
        public GiftTransaction $transaction,
        public Gift $gift,
        public User $sender,
        public User $receiver,
        public int $comboCount,
    ) {
    }

    /** @return array<int, PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'gift.sent';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'transaction_id' => $this->transaction->id,
            'sender'         => SocialPresenter::user($this->sender),
            'receiver'       => SocialPresenter::user($this->receiver),
            'gift'           => [
                'code'           => $this->gift->code,
                'animation_url'  => $this->gift->animation_url,
                'animation_type' => $this->gift->animation_type,
                'is_fullscreen'  => $this->gift->is_fullscreen,
                'duration_ms'    => $this->gift->duration_ms,
            ],
            'quantity'    => $this->transaction->quantity,
            'combo_count' => $this->comboCount,
        ];
    }
}
