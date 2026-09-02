<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** docs/02 §3.1 — D.2a, A.4. */
class Room extends Model
{
    use HasUuids, SoftDeletes;

    public const LIVE = 'live';
    public const IDLE = 'idle';
    public const CLOSED = 'closed';
    public const FORCE_CLOSED = 'force_closed';

    protected $fillable = [
        'uuid', 'room_code', 'owner_id', 'category_id', 'theme_id', 'name', 'description',
        'cover_url', 'announcement', 'visibility', 'password_hash', 'seat_count',
        'seat_layout', 'video_enabled', 'status', 'is_featured', 'is_pinned',
        'featured_until', 'listener_count', 'peak_listeners', 'total_diamonds_received',
        'started_at', 'ended_at', 'closed_by', 'close_reason',
    ];

    protected $hidden = ['password_hash'];

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

    protected function casts(): array
    {
        return [
            'video_enabled'           => 'boolean',
            'is_featured'             => 'boolean',
            'is_pinned'               => 'boolean',
            'featured_until'          => 'datetime',
            'started_at'              => 'datetime',
            'ended_at'                => 'datetime',
            'seat_count'              => 'integer',
            'listener_count'          => 'integer',
            'peak_listeners'          => 'integer',
            'total_diamonds_received' => 'integer',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class, 'category_id');
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(RoomTheme::class, 'theme_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'closed_by');
    }

    public function seats(): HasMany
    {
        return $this->hasMany(RoomSeat::class)->orderBy('seat_number');
    }

    public function members(): HasMany
    {
        return $this->hasMany(RoomMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->hasMany(RoomMember::class)->where('is_active', true);
    }

    // ------------------------------------------------------------------ scopes

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::LIVE);
    }

    /**
     * A.4b — "after that time it is no longer featured, without manual intervention".
     *
     * Expiry is enforced in the query rather than by a scheduled job, so a stalled or
     * un-run job can never leave a room featured past its window. The same pattern the
     * permission grants use.
     */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true)
            ->where(function (Builder $q) {
                $q->whereNull('featured_until')->orWhere('featured_until', '>', now());
            });
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }

        $term = trim($term);

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('room_code', 'like', "%{$term}%");
        });
    }

    // ----------------------------------------------------------------- helpers

    /** Featured *right now*, which is not the same as the `is_featured` column. */
    public function isCurrentlyFeatured(): bool
    {
        return $this->is_featured
            && ($this->featured_until === null || $this->featured_until->isFuture());
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::CLOSED, self::FORCE_CLOSED], true);
    }

    /**
     * Whether a kicked user is still blocked from rejoining this room — the re-entry block
     * C.2b requires. Derived from an active room-scoped sanction rather than a boolean flag
     * on the room, so the block lapses on its own once the window passes.
     */
    public function isBlockedForUser(int $userId): bool
    {
        return UserSanction::query()
            ->where('room_id', $this->id)
            ->where('user_id', $userId)
            ->where('type', UserSanction::ROOM_BAN)
            ->active()
            ->exists();
    }
}
