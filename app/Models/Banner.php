<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/02 §13 — A.10a.
 *
 * **Visibility is derived, never a stored flag flipped by a job.** A banner scheduled
 * 01–07 September has to be invisible on the 31st of August and gone on the 8th with
 * nothing having run. `is_active` is the operator's intent; `isLive()` is what is actually
 * true right now, and the two are reported separately so a screen never has to guess.
 */
class Banner extends Model
{
    public const PLACEMENTS = ['home_top', 'room_list', 'wallet', 'event'];

    public const ACTION_TYPES = ['none', 'url', 'room', 'event'];

    protected $fillable = [
        'title', 'image_url', 'placement', 'action_type', 'action_value',
        'sort_order', 'starts_at', 'ends_at', 'is_active', 'created_by', 'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at'        => 'datetime',
            'ends_at'          => 'datetime',
            'is_active'        => 'boolean',
            'sort_order'       => 'integer',
            'click_count'      => 'integer',
            'impression_count' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by');
    }

    /**
     * The same window, evaluated in SQL — so a filtered list agrees with `isLive()`.
     *
     * `approved_at`... there is no such column; approval is recorded by `approved_by`
     * being set, which is what B.3b turns on: a banner nobody with `cms.banner_approve`
     * has signed off does not show, however active and in-window it is.
     */
    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->whereNotNull('approved_by')
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    public function isApproved(): bool
    {
        return $this->approved_by !== null;
    }

    public function isLive(): bool
    {
        // B.3b — an unapproved banner is never live. Checked first, because it is the one
        // condition an operator staring at a correctly-scheduled banner will not think of.
        if (! $this->isApproved()) {
            return false;
        }

        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isFuture();
    }

    /** Why it is not showing — the question an operator actually asks. */
    public function state(): string
    {
        if (! $this->isApproved()) {
            return 'awaiting_approval';
        }

        if (! $this->is_active) {
            return 'off';
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return 'scheduled';
        }

        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return 'expired';
        }

        return 'live';
    }

    /** Clicks per impression. Null rather than zero when nothing has been shown yet. */
    public function clickRate(): ?float
    {
        return $this->impression_count > 0
            ? round($this->click_count / $this->impression_count, 4)
            : null;
    }
}
