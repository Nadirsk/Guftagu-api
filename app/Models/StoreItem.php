<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The purchasable-cosmetics catalogue behind the app's "Mall"/store screen — frames,
 * bubbles, entry banners, entrance effects, profile cards, mic sound waves, room backgrounds
 * and floating screens. One table discriminated by `type` rather than eight near-identical
 * ones; see the creating migration for why.
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

    /** The profile-page background (an animated card, usually MP4 or SVGA). */
    public const TYPE_PROFILE_CARD = 'profile_card';

    /** The "sound wave" ring drawn around your seat while you speak. */
    public const TYPE_MIC_WAVE = 'mic_wave';

    /** A background for the rooms you own. */
    public const TYPE_ROOM_BACKGROUND = 'room_background';

    /** The skin of the floating screen (the banner that flies across rooms) your big moments show in. */
    public const TYPE_FLOATING_SCREEN = 'floating_screen';

    public const TYPES = [
        self::TYPE_FRAME, self::TYPE_BUBBLE, self::TYPE_ENTRY_BANNER, self::TYPE_ENTRANCE_EFFECT,
        self::TYPE_PROFILE_CARD, self::TYPE_MIC_WAVE, self::TYPE_ROOM_BACKGROUND, self::TYPE_FLOATING_SCREEN,
    ];

    /** Types whose animation upload also records `animation_type` (SVGA / Lottie / MP4). */
    public const ANIMATED_TYPES = [
        self::TYPE_ENTRANCE_EFFECT, self::TYPE_PROFILE_CARD, self::TYPE_MIC_WAVE,
        self::TYPE_ROOM_BACKGROUND, self::TYPE_FLOATING_SCREEN,
    ];

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
