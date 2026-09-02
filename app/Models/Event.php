<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * docs/02 §9 — A.9a/b.
 *
 * The `status` column is the operator's intent; the **phase** is derived. A.9a demands
 * that a scheduled event flips to live at its start time and to ended at its end time
 * "with no manual step", so those two are computed from the clock rather than written by a
 * job. A stalled scheduler then cannot strand an event in the wrong state.
 */
class Event extends Model
{
    use HasUuids, SoftDeletes;

    public const DRAFT = 'draft';
    public const SCHEDULED = 'scheduled';
    public const CANCELLED = 'cancelled';

    public const UPCOMING = 'upcoming';
    public const LIVE = 'live';
    public const ENDED = 'ended';

    public const TYPES = ['event', 'tournament', 'lucky_draw'];

    protected $fillable = [
        'uuid', 'type', 'title_en', 'title_hi', 'description', 'banner_url', 'rules',
        'entry_type', 'entry_cost', 'eligibility', 'starts_at', 'ends_at', 'status',
        'created_by', 'approved_by', 'max_participants', 'is_featured',
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

    protected function casts(): array
    {
        return [
            'rules'            => 'array',
            'eligibility'      => 'array',
            'starts_at'        => 'datetime',
            'ends_at'          => 'datetime',
            'entry_cost'       => 'integer',
            'max_participants' => 'integer',
            'is_featured'      => 'boolean',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function participants(): HasMany
    {
        return $this->hasMany(EventParticipant::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(EventReward::class)->orderBy('rank_from');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(EventRewardClaim::class);
    }

    public function luckyDraw(): HasOne
    {
        return $this->hasOne(LuckyDraw::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    // ------------------------------------------------------------------ phase

    /**
     * What the app should show. Draft and cancelled are operator states and win outright;
     * everything else follows the clock.
     */
    public function phase(): string
    {
        if ($this->status === self::DRAFT || $this->status === self::CANCELLED) {
            return $this->status;
        }

        if ($this->starts_at->isFuture()) {
            return self::UPCOMING;
        }

        return $this->ends_at->isFuture() ? self::LIVE : self::ENDED;
    }

    public function isLive(): bool
    {
        return $this->phase() === self::LIVE;
    }

    public function hasEnded(): bool
    {
        return $this->phase() === self::ENDED;
    }

    /** Published to the app: anything scheduled, regardless of phase. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::SCHEDULED);
    }

    /**
     * Live right now. The window is evaluated in SQL so the app's list is correct at the
     * instant it is read, not at the instant a job last ran.
     */
    public function scopeLiveNow(Builder $query): Builder
    {
        return $query->where('status', self::SCHEDULED)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', self::SCHEDULED)->where('starts_at', '>', now());
    }

    public function scopeEnded(Builder $query): Builder
    {
        return $query->where('status', self::SCHEDULED)->where('ends_at', '<=', now());
    }
}
