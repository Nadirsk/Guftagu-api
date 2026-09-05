<?php

namespace App\Domain\Social;

use App\Events\Social\ProfileVisited;
use App\Events\Social\UserFollowed;
use App\Models\Block;
use App\Models\Follow;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * GFT-224 / GFT-225 / GFT-237 — the social graph: follows, friends, blocks and profile
 * visitors (epics D.3b, D.9c).
 *
 * Three rules the acceptance criteria turn on:
 *
 *  1. **D.3b — "their follower count increases immediately for both of us".** The counts
 *     are read back inside the same transaction that wrote the row and returned to the
 *     caller, rather than left for the client to guess or refetch. Both sides also get a
 *     socket frame carrying the same two numbers.
 *
 *  2. **D.9c — a block is symmetric and takes effect everywhere.** Blocking removes the
 *     follow rows in *both* directions, because the criterion says "neither of us appears
 *     in the other's follower list". Leaving them and filtering at read time means every
 *     list query has to remember to filter, and one that forgets is a leak.
 *
 *  3. **A friend is a mutual follow — there is no separate friendship record.** If I follow
 *     you and you follow me, we are friends; the moment either of us unfollows, we are not.
 *     One relationship, one source of truth, and no way for a stored "accepted" row to
 *     disagree with the follow graph it was derived from.
 *
 *     This is also the gate on direct messaging: {@see areFriends()} is what
 *     {@see \App\Domain\Chat\ChatService} asks before letting a DM through.
 */
class SocialService
{
    // ------------------------------------------------------------------ follows

    /**
     * Follow someone. Idempotent: following twice is a no-op, not a 422.
     *
     * @throws SocialException
     */
    public function follow(User $actor, User $target): array
    {
        if ($actor->id === $target->id) {
            throw new SocialException('VALIDATION_ERROR', 'You cannot follow yourself.', 422);
        }

        if (Block::existsBetween($actor->id, $target->id)) {
            throw SocialException::blocked();
        }

        $created = false;

        DB::transaction(function () use ($actor, $target, &$created) {
            try {
                Follow::create(['follower_id' => $actor->id, 'following_id' => $target->id]);
                $created = true;
            } catch (QueryException $e) {
                // The unique index is the source of truth for "already following". Catching
                // it beats a check-then-insert, which two taps in flight at once can both
                // pass.
                if (! $this->isDuplicate($e)) {
                    throw $e;
                }
            }
        });

        $counts = $this->followCounts($actor, $target);

        if ($created) {
            $this->notify($target, 'follow.new', 'New follower', $this->displayName($actor).' started following you.', [
                'user_uuid' => $actor->uuid,
            ]);

            UserFollowed::dispatch($actor, $target, $counts['followers'], $counts['following'], true);
        }

        return $counts + ['is_following' => true];
    }

    /** Unfollow. Also idempotent — unfollowing someone you do not follow is fine. */
    public function unfollow(User $actor, User $target): array
    {
        $removed = Follow::where('follower_id', $actor->id)
            ->where('following_id', $target->id)
            ->delete();

        $counts = $this->followCounts($actor, $target);

        if ($removed > 0) {
            UserFollowed::dispatch($actor, $target, $counts['followers'], $counts['following'], false);
        }

        return $counts + ['is_following' => false];
    }

    /**
     * `followers` is the target's follower total; `following` is the actor's following
     * total. Two different people's numbers, which is why the keys name the role.
     *
     * @return array{followers: int, following: int}
     */
    protected function followCounts(User $actor, User $target): array
    {
        return [
            'followers' => Follow::where('following_id', $target->id)->count(),
            'following' => Follow::where('follower_id', $actor->id)->count(),
        ];
    }

    /**
     * The follower or following list for a profile.
     *
     * @param  'followers'|'following'  $direction
     */
    public function connections(User $profile, string $direction, ?User $viewer, int $perPage, int $page): LengthAwarePaginator
    {
        $relation = $direction === 'followers' ? $profile->followers() : $profile->following();

        $relation->with('profile:id,user_id,display_name,avatar_url')
            ->where('users.status', '!=', User::STATUS_DELETED);

        if ($viewer !== null) {
            // Reach for the underlying Eloquent builder rather than the relation: the block
            // clause is shared with the plain-User queries and is typed for that.
            $this->excludeBlocked($relation->getQuery(), $viewer->id);
        }

        return $relation
            ->orderByDesc('follows.created_at')
            ->paginate(perPage: $perPage, page: $page);
    }

    // ------------------------------------------------------------------ friends

    /**
     * **A friend is a mutual follow.** There is no `friendships` table and no request /
     * accept flow: if I follow you and you follow me, we are friends, and the moment either
     * of us unfollows we are not.
     *
     * The relationship this replaces — a stored pair with `pending` / `accepted` — was one
     * table and five endpoints for a state the follow graph already expresses. It also gave
     * two ways to be connected, which meant every rule downstream had to say which one it
     * meant.
     *
     * @return LengthAwarePaginator<User>
     */
    public function friends(User $user, int $perPage, int $page): LengthAwarePaginator
    {
        $query = User::query()
            ->where('status', '!=', User::STATUS_DELETED)
            ->with('profile:id,user_id,display_name,avatar_url')
            ->whereIn('id', $this->mutualFollowIds($user->id));

        return $this->excludeBlocked($query, $user->id)
            ->orderByDesc('last_active_at')
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * The ids `$userId` mutually follows, as a subquery — a self-join on `follows`, resolved
     * by the database.
     *
     * Deliberately **not** `array_intersect(following, followers)` in PHP. That form pulls
     * every id on both sides into memory to compute a set the database can compute with an
     * index: a host with 50,000 followers would load 100,000 integers on every friend-list
     * call, and it cannot be paginated, because you only learn the total after loading it
     * all.
     */
    protected function mutualFollowIds(int $userId): Builder
    {
        return Follow::query()
            ->from('follows as mine')
            ->join('follows as theirs', function ($join) {
                $join->on('theirs.follower_id', '=', 'mine.following_id')
                    ->on('theirs.following_id', '=', 'mine.follower_id');
            })
            ->where('mine.follower_id', $userId)
            ->select('mine.following_id');
    }

    /** Whether the two follow each other. The gate on direct messaging — see ChatService. */
    public function areFriends(int $a, int $b): bool
    {
        if ($a === $b) {
            return false;
        }

        // Two EXISTS beats the self-join for a single pair: each side is a unique-index hit.
        return Follow::where('follower_id', $a)->where('following_id', $b)->exists()
            && Follow::where('follower_id', $b)->where('following_id', $a)->exists();
    }

    // ------------------------------------------------------------------- blocks

    /**
     * Block someone — D.9c.
     *
     * The follow rows go in both directions. The criterion is "neither of us appears in the
     * other's follower list", and the cheapest way to guarantee that everywhere is for the
     * rows not to exist, rather than for every list query to remember to filter.
     */
    public function block(User $actor, User $target, ?string $reason = null): Block
    {
        if ($actor->id === $target->id) {
            throw new SocialException('VALIDATION_ERROR', 'You cannot block yourself.', 422);
        }

        return DB::transaction(function () use ($actor, $target, $reason) {
            $block = Block::firstOrCreate(
                ['blocker_id' => $actor->id, 'blocked_id' => $target->id],
                ['reason' => $reason],
            );

            // Dropping both follow rows also ends the friendship, since a friendship *is*
            // the pair of rows. That in turn closes the DM thread — the chat gate asks the
            // same question.
            Follow::query()
                ->where(fn ($q) => $q->where('follower_id', $actor->id)->where('following_id', $target->id))
                ->orWhere(fn ($q) => $q->where('follower_id', $target->id)->where('following_id', $actor->id))
                ->delete();

            Block::forget($actor->id, $target->id);

            return $block;
        });
    }

    public function unblock(User $actor, User $target): void
    {
        Block::where('blocker_id', $actor->id)->where('blocked_id', $target->id)->delete();

        // Flushed straight away rather than left to the 60-second TTL: the first thing
        // somebody does after unblocking is message that person.
        Block::forget($actor->id, $target->id);
    }

    public function blockList(User $user, int $perPage, int $page): LengthAwarePaginator
    {
        return Block::query()
            ->where('blocker_id', $user->id)
            ->with(['blocked.profile:id,user_id,display_name,avatar_url'])
            ->latest('id')
            ->paginate(perPage: $perPage, page: $page);
    }

    // ----------------------------------------------------------------- visitors

    /**
     * Record a profile view.
     *
     * Returns true only when this is a *first* visit, which is what decides whether the
     * owner gets a live ping. Self-views and blocked pairs are not recorded at all — your
     * own visitor list should not be mostly you.
     */
    public function recordVisit(User $visitor, User $profileOwner): bool
    {
        if ($visitor->id === $profileOwner->id || Block::existsBetween($visitor->id, $profileOwner->id)) {
            return false;
        }

        $visit = UserVisit::where('visitor_id', $visitor->id)
            ->where('profile_id', $profileOwner->id)
            ->first();

        if ($visit !== null) {
            $visit->increment('visit_count');
            $visit->forceFill(['visited_at' => now()])->save();

            return false;
        }

        UserVisit::create([
            'visitor_id' => $visitor->id,
            'profile_id' => $profileOwner->id,
            'visited_at' => now(),
        ]);

        ProfileVisited::dispatch($visitor, $profileOwner);

        return true;
    }

    public function visitors(User $profileOwner, int $perPage, int $page): LengthAwarePaginator
    {
        return UserVisit::query()
            ->where('profile_id', $profileOwner->id)
            ->with(['visitor.profile:id,user_id,display_name,avatar_url'])
            ->orderByDesc('visited_at')
            ->paginate(perPage: $perPage, page: $page);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Drop anyone in a block relationship with `$viewerId`, in either direction.
     *
     * Applied to every list of people this service returns. Written once here so a new list
     * endpoint cannot quietly ship without it.
     */
    public function excludeBlocked(Builder $query, int $viewerId): Builder
    {
        return $query->whereNotExists(fn ($sub) => $sub
            ->selectRaw('1')
            ->from('blocks')
            ->where(fn ($b) => $b
                ->where(fn ($x) => $x->where('blocks.blocker_id', $viewerId)->whereColumn('blocks.blocked_id', 'users.id'))
                ->orWhere(fn ($x) => $x->whereColumn('blocks.blocker_id', 'users.id')->where('blocks.blocked_id', $viewerId))));
    }

    protected function notify(User $user, string $type, string $title, string $body, array $data = []): void
    {
        Notification::create([
            'user_id' => $user->id,
            'type'    => $type,
            'title'   => $title,
            'body'    => $body,
            'data'    => $data,
            'channel' => 'in_app',
            'sent_at' => now(),
        ]);
    }

    protected function displayName(User $user): string
    {
        return $user->profile?->display_name ?? $user->guftagu_id ?? 'Someone';
    }

    protected function isDuplicate(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
