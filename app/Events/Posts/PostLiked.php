<?php

namespace App\Events\Posts;

use App\Models\Post;
use App\Models\User;
use App\Support\SocialPresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A like or an unlike — one event with a direction, because the client applies both the
 * same way: replace `like_count`, flip the heart if it was me.
 *
 * Two channels, two audiences:
 *   `post.{uuid}`  everyone with the post open — the counter moves under their thumb.
 *   `user.{uuid}`  the author, wherever they are in the app — this is the notification.
 *
 * The author is skipped on the second channel when they liked their own post; being told
 * about your own tap is noise.
 */
class PostLiked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Post $post,
        public User $actor,
        public bool $liked = true,
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel("post.{$this->post->uuid}")];

        if ($this->post->user_id !== $this->actor->id) {
            $channels[] = new PrivateChannel("user.{$this->post->author->uuid}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return $this->liked ? 'post.liked' : 'post.unliked';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'post_uuid'  => $this->post->uuid,
            'actor'      => SocialPresenter::user($this->actor),
            'liked'      => $this->liked,
            'like_count' => (int) $this->post->like_count,
        ];
    }
}
