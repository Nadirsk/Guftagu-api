<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * "Delete for everyone". Only ever fired for that case — "delete for me" changes nothing
 * for anyone else, so broadcasting it would tell the other side about a decision that was
 * explicitly not theirs to see.
 */
class MessageDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $conversationUuid,
        public string $messageUuid,
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->conversationUuid}")];
    }

    public function broadcastAs(): string
    {
        return 'message.deleted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_uuid' => $this->conversationUuid,
            'message_uuid'      => $this->messageUuid,
        ];
    }
}
