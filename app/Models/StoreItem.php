<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The purchasable-cosmetics catalogue behind the app's "Mall"/store screen — frames,
 * bubbles, entry banners and entrance effects. One table discriminated by `type` rather
 * than four near-identical ones; see the creating migration for why.
 *
 * `badges` is deliberately not a type here — badges are earned (`is_auto_awarded`), never
 * bought, so they stay in their own table and out of this purchase-flow-shaped one.
 */
class StoreItem extends Model
{
    public const TYPE_FRAME = 'frame';

    public const TYPE_BUBBLE = 'bubble';

    public const TYPE_ENTRY_BANNER = 'entry_banner';

    public const TYPE_ENTRANCE_EFFECT = 'entrance_effect';

    public const TYPES = [self::TYPE_FRAME, self::TYPE_BUBBLE, self::TYPE_ENTRY_BANNER, self::TYPE_ENTRANCE_EFFECT];

    public const SOURCES = ['vip', 'event', 'purchase', 'admin'];

    public const TRIGGERS = ['vip_entry', 'big_gift', 'level_up', 'event'];

    protected $fillable = [
        'type', 'name', 'image_url', 'animation_url', 'animation_type', 'duration_ms',
        'trigger', 'min_gift_coin_value', 'source', 'coin_price', 'rental_days',
        'required_vip_tier_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'duration_ms'         => 'integer',
            'min_gift_coin_value' => 'integer',
            'coin_price'          => 'integer',
            'rental_days'         => 'integer',
            'is_active'           => 'boolean',
        ];
    }

    public function requiredVipTier(): BelongsTo
    {
        return $this->belongsTo(VipTier::class, 'required_vip_tier_id');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /** Whether ownership of this item ever expires — see `rental_days`. */
    public function isPermanent(): bool
    {
        return $this->rental_days === null;
    }
}
