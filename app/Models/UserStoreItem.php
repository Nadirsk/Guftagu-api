<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's ownership of one {@see StoreItem} (frame, bubble, entry banner or entrance
 * effect) — bought, or granted by VIP/an event/an admin. `expires_at` null means
 * permanent, same convention as `StoreItem::rental_days`.
 */
class UserStoreItem extends Model
{
    public const SOURCES = ['purchase', 'vip', 'event', 'admin'];

    protected $fillable = ['user_id', 'store_item_id', 'starts_at', 'expires_at', 'source'];

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

    public function storeItem(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class);
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
