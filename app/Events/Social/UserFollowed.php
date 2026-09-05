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
 * `follow.new` from docs/03 §13 — D.3b's *"their follower count increases immediately for
 * both of us"*.
 *
 * Both sides get a frame, and both carry the pair of counts. The person followed needs the
 * notification; the follower needs their own "following" number to move without a refetch.
 */
class UserFollowed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public User $follower,
        public User $following,
        public int $followerCount,
        public int $followingCount,
        public bool $followed = true,
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->following->uuid}"),
            new PrivateChannel("user.{$this->follower->uuid}"),
        ];
    }

    public function broadcastAs(): string
    {
        return $this->followed ? 'follow.new' : 'follow.removed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'follower'  => SocialPresenter::user($this->follower),
            'following' => SocialPresenter::user($this->following),
            'followed'  => $this->followed,
            'counts'    => [
                // Whose numbers these are: the person being followed gained a follower,
                // the follower gained a following. Naming them by role rather than by
                // "mine/theirs" keeps both clients reading the same field.
                'followers_of_following' => $this->followerCount,
                'following_of_follower'  => $this->followingCount,
            ],
        ];
    }
}
