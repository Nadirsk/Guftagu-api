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
 * The tick moved — somebody's device received messages, or somebody opened the thread.
 *
 * One event with a `status` of `delivered` or `read`, because the sender's client does the
 * same thing with both: walk its own messages and repaint the tick.
 *
 * **It carries the thread aggregate, not just the actor's own mark.** In a group, my
 * message turns blue only when *everyone* has read it, so a frame saying "Sara read up to
 * 42" is not enough on its own — the client would have to hold every participant's mark and
 * recompute the minimum itself. The server already has that number, so it sends it:
 * `delivered_up_to` / `read_up_to` are the watermarks for the whole thread, from the point
 * of view of the person the frame is telling. Applying it is then two comparisons.
 *
 * `ShouldBroadcastNow` for the same reason as {@see MessageSent}: a tick that arrives a
 * queue-depth later is a tick that looks broken.
 */
class MessageStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const DELIVERED = 'delivered';
    public const READ = 'read';

    public function __construct(
        public string $conversationUuid,
        /** Who acked. */
        public User $actor,
        /** self::DELIVERED or self::READ */
        public string $status,
        /** The furthest message uuid the actor has now reached. */
        public ?string $upToMessageUuid,
        /** Thread-wide watermark uuids, for the recipient of this frame to repaint against. */
        public ?string $deliveredUpToUuid = null,
        public ?string $readUpToUuid = null,
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->conversationUuid}")];
    }

    public function broadcastAs(): string
    {
        return "conversation.{$this->status}";
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_uuid' => $this->conversationUuid,
            'actor'             => SocialPresenter::user($this->actor),
            'status'            => $this->status,
            'up_to_message_uuid' => $this->upToMessageUuid,
            // Everyone still in the thread has at least reached these.
            'delivered_up_to'   => $this->deliveredUpToUuid,
            'read_up_to'        => $this->readUpToUuid,
        ];
    }
}
