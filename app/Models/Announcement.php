<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §13 — A.10a. Same derived-window rule as Banner.
class Announcement extends Model
{
    public const TYPES = ['marquee', 'popup', 'banner'];

    protected $fillable = [
        'title_en', 'title_hi', 'body_en', 'body_hi', 'type',
        'target_roles', 'starts_at', 'ends_at', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'target_roles' => 'array',
            'starts_at'    => 'datetime',
            'ends_at'      => 'datetime',
            'is_active'    => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    public function isLive(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isFuture();
    }

    public function state(): string
    {
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

    /** Empty or absent means everyone — narrowing is opt-in. */
    public function appliesToEveryone(): bool
    {
        return empty($this->target_roles);
    }
}
