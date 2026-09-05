<?php

namespace App\Events\Social;

use App\Models\User;
use App\Support\SocialPresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody opened your profile.
 *
 * Only fired on a *new* visitor, never on a repeat. A live "someone is looking at you"
 * ping that fires every time one person refreshes is not a feature, it is a rattle.
 */
class ProfileVisited implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public User $visitor,
        public User $profileOwner,
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->profileOwner->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'visitor.new';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['visitor' => SocialPresenter::user($this->visitor)];
    }
}
