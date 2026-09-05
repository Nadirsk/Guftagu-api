<?php

namespace App\Events\Chat;

use App\Models\User;
use App\Support\SocialPresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The typing indicator.
 *
 * Nothing is persisted — a typing state is true for about two seconds and is worthless the
 * moment after. It goes out over the socket and is never written down. `dontBroadcastToCurrentUser`
 * is applied at the dispatch site so the typist does not see their own indicator.
 */
class UserTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $conversationUuid,
        public User $user,
        public bool $typing = true,
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->conversationUuid}")];
    }

    public function broadcastAs(): string
    {
        return 'typing';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_uuid' => $this->conversationUuid,
            'user'              => SocialPresenter::user($this->user),
            'typing'            => $this->typing,
        ];
    }
}
