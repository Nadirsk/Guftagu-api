<?php

namespace App\Events\Posts;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A moment removed by its author, or hidden by a Moderator.
 *
 * Carries plain scalars rather than the model: the row is already soft-deleted by the time
 * this is queued, and `SerializesModels` would fail to re-resolve it on the worker.
 *
 * Broadcast on the public `feed` channel as well as the post's own, because a client
 * holding the card in a list may never have subscribed to `post.{uuid}` — that
 * subscription happens when the thread is opened.
 */
class PostDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $postUuid,
        public string $authorUuid,
        public ?string $reason = null,
    ) {
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("post.{$this->postUuid}"),
            new Channel('feed'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'post.deleted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'post_uuid'   => $this->postUuid,
            'author_uuid' => $this->authorUuid,
            'reason'      => $this->reason,
        ];
    }
}
