<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One granted term of VIP on a user — see {@see \App\Domain\Store\InventoryService}
 * for how a repeat grant either extends this row or starts a new one.
 */
class UserVipSubscription extends Model
{
    public const SOURCES = ['purchase', 'event', 'admin'];

    protected $fillable = ['user_id', 'vip_tier_id', 'starts_at', 'expires_at', 'source'];

    protected function casts(): array
    {
        return [
            'starts_at'  => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vipTier(): BelongsTo
    {
        return $this->belongsTo(VipTier::class);
    }

    public function isActive(): bool
    {
        return $this->expires_at->isFuture();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
