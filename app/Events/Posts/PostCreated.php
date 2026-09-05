<?php

namespace App\Events\Posts;

use App\Models\Post;
use App\Support\SocialPresenter;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new moment, pushed to the people entitled to see it (D.3d).
 *
 * The fan-out differs by visibility, and that is the whole design:
 *
 *   `public`     one public `feed` channel. Every client is already on it, so this is one
 *                frame regardless of audience size.
 *   `followers`  one private frame per follower. There is no shared channel that means
 *                "this author's followers", so the fan-out is explicit — and it is capped,
 *                see below.
 *   `private`    nothing. Nobody else may see it, so nobody else is told it exists.
 *
 * **The cap.** An author with 50,000 followers would otherwise emit 50,000 frames inside
 * one request. Beyond {@see FANOUT_LIMIT} the push is dropped and the post is simply picked
 * up by the next `GET /feed` — a late post is a nuisance, a stalled queue is an outage.
 * Queued (not `ShouldBroadcastNow`) for the same reason: nobody is waiting on this frame
 * the way they wait on a chat message.
 */
class PostCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const FANOUT_LIMIT = 2000;

    public function __construct(public Post $post)
    {
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        if ($this->post->is_hidden || $this->post->visibility === Post::PRIVATE) {
            return [];
        }

        if ($this->post->visibility === Post::PUBLIC) {
            return [new Channel('feed')];
        }

        $followerUuids = $this->post->author
            ->followers()
            ->limit(self::FANOUT_LIMIT + 1)
            ->pluck('users.uuid');

        if ($followerUuids->count() > self::FANOUT_LIMIT) {
            return [];
        }

        return $followerUuids
            ->map(fn (string $uuid) => new PrivateChannel("user.{$uuid}"))
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'post.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['post' => SocialPresenter::post($this->post)];
    }
}
