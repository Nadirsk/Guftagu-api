<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** docs/02 §6 — A.6a/b. */
class Gift extends Model
{
    public const TIERS = ['basic', 'premium', 'luxury', 'legendary'];
    public const ANIMATION_TYPES = ['lottie', 'svga', 'mp4'];

    protected $fillable = [
        'code', 'name_en', 'name_hi', 'category_id', 'tier', 'coin_price', 'diamond_value',
        'thumbnail_url', 'animation_url', 'animation_type', 'duration_ms', 'is_fullscreen',
        'is_combo_enabled', 'max_combo', 'required_vip_tier_id', 'is_limited', 'stock',
        'available_from', 'available_to', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'coin_price'       => 'integer',
            'diamond_value'    => 'integer',
            'duration_ms'      => 'integer',
            'is_fullscreen'    => 'boolean',
            'is_combo_enabled' => 'boolean',
            'max_combo'        => 'integer',
            'is_limited'       => 'boolean',
            'stock'            => 'integer',
            'available_from'   => 'datetime',
            'available_to'     => 'datetime',
            'is_active'        => 'boolean',
            'sort_order'       => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GiftCategory::class, 'category_id');
    }

    public function requiredVipTier(): BelongsTo
    {
        return $this->belongsTo(VipTier::class, 'required_vip_tier_id');
    }

    /**
     * Sellable right now: active, inside its window, and not sold out.
     *
     * Availability is decided in the query rather than by a job that flips `is_active`,
     * so a drop opens and closes on time even if nothing is scheduled to run.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q) {
                $q->whereNull('available_from')->orWhere('available_from', '<=', now());
            })
            ->where(function (Builder $q) {
                $q->whereNull('available_to')->orWhere('available_to', '>', now());
            })
            ->where(function (Builder $q) {
                // NULL stock is unlimited; 0 is sold out.
                $q->whereNull('stock')->orWhere('stock', '>', 0);
            });
    }

    public function isSoldOut(): bool
    {
        return $this->is_limited && $this->stock !== null && $this->stock <= 0;
    }

    public function isWithinWindow(): bool
    {
        if ($this->available_from !== null && $this->available_from->isFuture()) {
            return false;
        }

        return ! ($this->available_to !== null && $this->available_to->isPast());
    }

    public function isAvailable(): bool
    {
        return $this->is_active && $this->isWithinWindow() && ! $this->isSoldOut();
    }
}
