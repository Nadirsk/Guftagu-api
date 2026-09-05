<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A moment — docs/02 §12, epic D.3d (**descope lever #1**).
 *
 * D.3d turns on one sentence: *"a non-follower cannot see it via the feed **or** by direct
 * id."* That is why {@see scopeVisibleTo()} exists and why the show endpoint runs it too.
 * A visibility rule applied only when building the feed is not a visibility rule — it is a
 * sort order, and the post is still one GET away.
 */
class Post extends Model
{
    use HasUuids, SoftDeletes;

    public const TEXT = 'text';
    public const IMAGE = 'image';
    public const AUDIO = 'audio';

    public const TYPES = [self::TEXT, self::IMAGE, self::AUDIO];

    public const PUBLIC = 'public';
    public const FOLLOWERS = 'followers';
    public const PRIVATE = 'private';

    public const VISIBILITIES = [self::PUBLIC, self::FOLLOWERS, self::PRIVATE];

    protected $fillable = [
        'uuid', 'user_id', 'type', 'body', 'media_urls', 'visibility',
        'like_count', 'comment_count', 'is_hidden', 'hidden_by', 'hidden_reason',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getIncrementing(): bool
    {
        return true;
    }

    public function getKeyType(): string
    {
        return 'int';
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'media_urls'    => 'array',
            'is_hidden'     => 'boolean',
            'like_count'    => 'integer',
            'comment_count' => 'integer',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(PostLike::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    // ------------------------------------------------------------------ scopes

    /**
     * Everything `$viewer` is allowed to see, in one clause.
     *
     * Four rules, in the order they matter:
     *   - your own posts are always yours, hidden or not;
     *   - a hidden post is invisible to everyone else, whatever its visibility says;
     *   - `private` never leaves the author;
     *   - `followers` needs a `follows` row from the viewer to the author, and `public` is
     *     open — but both still lose to a block in either direction (D.9c).
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        $viewerId = $viewer?->id;

        return $query->where(function (Builder $q) use ($viewerId) {
            if ($viewerId !== null) {
                $q->where('posts.user_id', $viewerId);
            }

            $q->orWhere(function (Builder $open) use ($viewerId) {
                $open->where('posts.is_hidden', false);

                if ($viewerId === null) {
                    // Anonymous readers get public posts only.
                    $open->where('posts.visibility', self::PUBLIC);

                    return;
                }

                $open->where(fn (Builder $vis) => $vis
                    ->where('posts.visibility', self::PUBLIC)
                    ->orWhere(fn (Builder $f) => $f
                        ->where('posts.visibility', self::FOLLOWERS)
                        ->whereExists(fn ($sub) => $sub
                            ->selectRaw('1')
                            ->from('follows')
                            ->whereColumn('follows.following_id', 'posts.user_id')
                            ->where('follows.follower_id', $viewerId))));

                $open->whereNotExists(fn ($sub) => $sub
                    ->selectRaw('1')
                    ->from('blocks')
                    ->where(fn ($b) => $b
                        ->where(fn ($x) => $x->where('blocks.blocker_id', $viewerId)->whereColumn('blocks.blocked_id', 'posts.user_id'))
                        ->orWhere(fn ($x) => $x->whereColumn('blocks.blocker_id', 'posts.user_id')->where('blocks.blocked_id', $viewerId))));
            });
        });
    }

    // ----------------------------------------------------------------- helpers

    public function isVisibleTo(?User $viewer): bool
    {
        return static::query()->whereKey($this->getKey())->visibleTo($viewer)->exists();
    }

    /** Who may delete it: the author. Moderators hide rather than delete (D.3d). */
    public function isAuthoredBy(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->id;
    }
}
