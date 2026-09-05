<?php

namespace App\Events\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Support\SocialPresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A chat message (D.4). `docs/03 §13` calls this `message.new`.
 *
 * **`ShouldBroadcastNow`, not `ShouldBroadcast`.** Everything else in this module can wait
 * for a worker; a chat message cannot. A queued broadcast puts the whole queue depth
 * between "sent" and "delivered", and the one thing a messenger must not do is deliver
 * messages late. The cost is that the sender's request waits on the Reverb publish, which
 * is a few milliseconds on a healthy socket server.
 *
 * Two audiences again:
 *   `conversation.{uuid}`  the thread, for anyone who has it open.
 *   `user.{uuid}`          each other participant, so the DM list badge and the push
 *                          notification land without the thread being open.
 *
 * The sender is not on the second list. Their own message is already on screen — echoing
 * it back as an unread bump is how a chat app tells you that you have unread messages from
 * yourself.
 */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, string>  $recipientUuids  every participant except the sender
     */
    public function __construct(
        public Conversation $conversation,
        public Message $message,
        public array $recipientUuids = [],
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel("conversation.{$this->conversation->uuid}")];

        foreach ($this->recipientUuids as $uuid) {
            $channels[] = new PrivateChannel("user.{$uuid}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.new';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_uuid' => $this->conversation->uuid,
            'message'           => SocialPresenter::message($this->message)
                + ['conversation_uuid' => $this->conversation->uuid],
        ];
    }
}
