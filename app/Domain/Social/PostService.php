<?php

namespace App\Domain\Social;

use App\Domain\Moderation\ContentFilter;
use App\Events\Posts\PostCommentDeleted;
use App\Events\Posts\PostCommented;
use App\Events\Posts\PostCreated;
use App\Events\Posts\PostDeleted;
use App\Events\Posts\PostLiked;
use App\Models\Block;
use App\Models\Notification;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * GFT-228 — moments: posts, likes, comments, visibility and the moderation hook
 * (epic D.3d, **descope lever #1**).
 *
 * The criterion this module lives or dies by: *"Given I post a moment visible to followers
 * only, then a non-follower cannot see it via the feed **or** by direct id."* Both halves
 * go through the one clause in {@see Post::scopeVisibleTo()} — the feed builds on it and so
 * does {@see show()}. There is deliberately no second, hand-rolled check for the direct-id
 * path, because two implementations of one rule is how the two stop agreeing.
 *
 * Counters (`like_count`, `comment_count`) are written in the same transaction as the row
 * they count, using a bare `increment`/`decrement` so two concurrent likes cannot both read
 * 4 and both write 5.
 */
class PostService
{
    public function __construct(protected ContentFilter $filter)
    {
    }

    // ------------------------------------------------------------------ writing

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws SocialException
     */
    public function create(User $author, array $data): Post
    {
        $body = trim((string) ($data['body'] ?? ''));
        $media = array_values(array_filter($data['media_urls'] ?? []));

        if ($body === '' && $media === []) {
            throw new SocialException('VALIDATION_ERROR', 'A post needs either text or media.', 422);
        }

        if ($body !== '') {
            // docs/02 §11 gives `banned_words.scope` four values and none of them is
            // "post". `chat` is the closest fit and the one an operator would expect a
            // slur ban to cover; a fifth scope would mean migrating every existing row's
            // scope array, for no behavioural gain.
            $result = $this->filter->checkAndFlag($body, 'chat', $author->id);

            if ($result->blocked()) {
                throw new SocialException('BANNED_WORD_DETECTED', 'That post contains words that are not allowed.', 422);
            }

            $body = $result->filtered;
        }

        $post = Post::create([
            'user_id'    => $author->id,
            'type'       => $data['type'] ?? ($media === [] ? Post::TEXT : Post::IMAGE),
            'body'       => $body === '' ? null : $body,
            'media_urls' => $media === [] ? null : $media,
            'visibility' => $data['visibility'] ?? Post::PUBLIC,
        ]);

        $post->setRelation('author', $author);

        PostCreated::dispatch($post);

        return $post;
    }

    /**
     * Delete a moment. Author only — a Moderator hides instead, which is reversible.
     *
     * @throws SocialException
     */
    public function delete(Post $post, User $actor): void
    {
        if (! $post->isAuthoredBy($actor)) {
            throw SocialException::notVisible();
        }

        $uuid = $post->uuid;
        $authorUuid = $post->author->uuid;

        $post->delete();

        PostDeleted::dispatch($uuid, $authorUuid);
    }

    // -------------------------------------------------------------------- likes

    /**
     * Like a post. Idempotent, and the counter is authoritative either way — a client that
     * double-taps gets the same count back rather than a 422 it has to interpret.
     *
     * @throws SocialException
     */
    public function like(Post $post, User $actor): Post
    {
        $this->assertVisible($post, $actor);

        $created = false;

        DB::transaction(function () use ($post, $actor, &$created) {
            try {
                PostLike::create(['post_id' => $post->id, 'user_id' => $actor->id]);
                $created = true;
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }

                return;
            }

            $post->increment('like_count');
        });

        $post->refresh();

        if ($created) {
            if ($post->user_id !== $actor->id) {
                $this->notify($post->author, 'post.liked', 'New like',
                    $this->displayName($actor).' liked your moment.', ['post_uuid' => $post->uuid]);
            }

            PostLiked::dispatch($post, $actor, true);
        }

        return $post;
    }

    /** @throws SocialException */
    public function unlike(Post $post, User $actor): Post
    {
        $this->assertVisible($post, $actor);

        $removed = 0;

        DB::transaction(function () use ($post, $actor, &$removed) {
            $removed = PostLike::where('post_id', $post->id)->where('user_id', $actor->id)->delete();

            if ($removed > 0 && $post->like_count > 0) {
                $post->decrement('like_count');
            }
        });

        $post->refresh();

        if ($removed > 0) {
            PostLiked::dispatch($post, $actor, false);
        }

        return $post;
    }

    // ----------------------------------------------------------------- comments

    /**
     * @param  string|null  $parentUuid  the comment being replied to, if any
     *
     * @throws SocialException
     */
    public function comment(Post $post, User $actor, string $body, ?string $parentUuid = null): PostComment
    {
        $this->assertVisible($post, $actor);

        $body = trim($body);

        if ($body === '') {
            throw new SocialException('VALIDATION_ERROR', 'An empty comment is not a comment.', 422);
        }

        $result = $this->filter->checkAndFlag($body, 'chat', $actor->id);

        if ($result->blocked()) {
            throw new SocialException('BANNED_WORD_DETECTED', 'That comment contains words that are not allowed.', 422);
        }

        $parentId = null;

        if ($parentUuid !== null) {
            // A reply has to belong to the same post, and replies are one level deep — a
            // parent that is itself a reply would build a thread the client cannot render,
            // so the grandparent is used instead of nesting further.
            $parent = PostComment::where('uuid', $parentUuid)->where('post_id', $post->id)->first();

            if ($parent === null) {
                throw new SocialException('VALIDATION_ERROR', 'That comment is not on this post.', 422);
            }

            $parentId = $parent->parent_id ?? $parent->id;
        }

        $comment = DB::transaction(function () use ($post, $actor, $result, $parentId) {
            $comment = PostComment::create([
                'post_id'   => $post->id,
                'user_id'   => $actor->id,
                'parent_id' => $parentId,
                'body'      => $result->filtered,
            ]);

            $post->increment('comment_count');

            return $comment;
        });

        $post->refresh();
        $comment->setRelation('author', $actor);

        if ($post->user_id !== $actor->id) {
            $this->notify($post->author, 'post.commented', 'New comment',
                $this->displayName($actor).' commented on your moment.', ['post_uuid' => $post->uuid]);
        }

        PostCommented::dispatch($post, $comment);

        return $comment;
    }

    /**
     * Remove a comment. Either the comment's author or the post's author may do it — it is
     * your comment, or it is your moment.
     *
     * @throws SocialException
     */
    public function deleteComment(PostComment $comment, User $actor): void
    {
        $post = $comment->post;

        if ($comment->user_id !== $actor->id && $post->user_id !== $actor->id) {
            throw SocialException::notVisible();
        }

        if ($comment->is_deleted) {
            return;
        }

        DB::transaction(function () use ($comment, $post) {
            // Tombstoned, not removed: replies hang off this node and a hole in the middle
            // of a thread orphans them.
            $comment->forceFill(['is_deleted' => true, 'body' => ''])->save();

            if ($post->comment_count > 0) {
                $post->decrement('comment_count');
            }
        });

        $post->refresh();

        PostCommentDeleted::dispatch($post, $comment);
    }

    // ------------------------------------------------------------------ reading

    /**
     * The feed.
     *
     * `following` (the default) is "people I follow, plus me" — the moments screen.
     * `public` is discovery: every public moment, newest first.
     * `user` is one person's profile grid.
     *
     * Keyset-paginated on `posts.id`: a feed that grows while you scroll must not show the
     * same row twice, which is exactly what `OFFSET` does here.
     *
     * @return array{items: Collection<int, Post>, next_cursor: ?int, liked: array<int, bool>}
     */
    public function feed(?User $viewer, string $scope, ?int $afterId, int $limit, ?User $profile = null): array
    {
        $query = Post::query()
            ->with(['author.profile:id,user_id,display_name,avatar_url'])
            ->visibleTo($viewer);

        if ($scope === 'following' && $viewer !== null) {
            $query->where(fn ($q) => $q
                ->where('posts.user_id', $viewer->id)
                ->orWhereExists(fn ($sub) => $sub
                    ->selectRaw('1')
                    ->from('follows')
                    ->whereColumn('follows.following_id', 'posts.user_id')
                    ->where('follows.follower_id', $viewer->id)));
        }

        if ($scope === 'user' && $profile !== null) {
            $query->where('posts.user_id', $profile->id);
        }

        if ($afterId !== null) {
            $query->where('posts.id', '<', $afterId);
        }

        // One extra row is the cheapest way to know whether there is a next page without a
        // second COUNT over the same predicate.
        $rows = $query->orderByDesc('posts.id')->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $items = $hasMore ? $rows->take($limit) : $rows;

        return [
            'items'       => $items,
            'next_cursor' => $hasMore ? $items->last()?->id : null,
            'liked'       => $this->likedMap($viewer, $items),
        ];
    }

    /** @throws SocialException */
    public function show(Post $post, ?User $viewer): Post
    {
        if (! $post->isVisibleTo($viewer)) {
            throw SocialException::notVisible();
        }

        return $post->load(['author.profile:id,user_id,display_name,avatar_url']);
    }

    /**
     * @return array{items: Collection<int, PostComment>, next_cursor: ?int}
     *
     * @throws SocialException
     */
    public function comments(Post $post, ?User $viewer, ?int $afterId, int $limit): array
    {
        $this->assertVisible($post, $viewer);

        $query = PostComment::query()
            ->where('post_id', $post->id)
            ->with(['author.profile:id,user_id,display_name,avatar_url']);

        if ($viewer !== null) {
            // A blocked person's comments disappear from the thread in both directions —
            // D.9c's "cannot ... see" applies to what they have already written too.
            $query->whereNotExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('blocks')
                ->where(fn ($b) => $b
                    ->where(fn ($x) => $x->where('blocks.blocker_id', $viewer->id)->whereColumn('blocks.blocked_id', 'post_comments.user_id'))
                    ->orWhere(fn ($x) => $x->whereColumn('blocks.blocker_id', 'post_comments.user_id')->where('blocks.blocked_id', $viewer->id))));
        }

        if ($afterId !== null) {
            $query->where('post_comments.id', '>', $afterId);
        }

        // Ascending: a comment thread reads top to bottom, unlike a feed.
        $rows = $query->orderBy('post_comments.id')->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $items = $hasMore ? $rows->take($limit) : $rows;

        return [
            'items'       => $items,
            'next_cursor' => $hasMore ? $items->last()?->id : null,
        ];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Which of these posts the viewer has already liked, in one query rather than one per
     * card. Without this the feed does N+1 lookups just to colour the hearts.
     *
     * @param  Collection<int, Post>  $posts
     * @return array<int, bool>
     */
    public function likedMap(?User $viewer, Collection $posts): array
    {
        if ($viewer === null || $posts->isEmpty()) {
            return [];
        }

        return PostLike::query()
            ->where('user_id', $viewer->id)
            ->whereIn('post_id', $posts->pluck('id'))
            ->pluck('post_id')
            ->mapWithKeys(fn (int $id) => [$id => true])
            ->all();
    }

    /** @throws SocialException */
    protected function assertVisible(Post $post, ?User $viewer): void
    {
        if ($viewer !== null && Block::existsBetween($viewer->id, $post->user_id)) {
            throw SocialException::notVisible();
        }

        if (! $post->isVisibleTo($viewer)) {
            throw SocialException::notVisible();
        }
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
}
