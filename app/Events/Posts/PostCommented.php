<?php

namespace App\Events\Posts;

use App\Models\Post;
use App\Models\PostComment;
use App\Support\SocialPresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new comment. Same two audiences as {@see PostLiked}: the open thread, and the author's
 * own channel so the notification lands without the post being on screen.
 */
class PostCommented implements ShouldBroadcast
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
        $channels = [new PrivateChannel("post.{$this->post->uuid}")];

        if ($this->post->user_id !== $this->comment->user_id) {
            $channels[] = new PrivateChannel("user.{$this->post->author->uuid}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'post.commented';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'post_uuid'     => $this->post->uuid,
            'comment'       => SocialPresenter::comment($this->comment) + ['post_uuid' => $this->post->uuid],
            'comment_count' => (int) $this->post->comment_count,
        ];
    }
}
