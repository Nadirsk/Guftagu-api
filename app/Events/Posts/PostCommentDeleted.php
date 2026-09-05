<?php

namespace App\Events\Posts;

use App\Models\Post;
use App\Models\PostComment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A comment removed. Clients replace it with a tombstone rather than dropping the node. */
class PostCommentDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Post $post,
        public PostComment $comment,
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("post.{$this->post->uuid}")];
    }

    public function broadcastAs(): string
    {
        return 'post.comment.deleted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'post_uuid'     => $this->post->uuid,
            'comment_uuid'  => $this->comment->uuid,
            'comment_count' => (int) $this->post->comment_count,
        ];
    }
}
