<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A badge ("Medal" in the app's own wording) awarded to a user. Most are permanent;
 * `expires_at` lets an event grant one for a fixed number of days instead.
 */
class UserBadge extends Model
{
    public const SOURCES = ['event', 'admin', 'auto'];

    protected $fillable = ['user_id', 'badge_id', 'awarded_at', 'expires_at', 'source'];

    protected function casts(): array
    {
        return [
            'awarded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
