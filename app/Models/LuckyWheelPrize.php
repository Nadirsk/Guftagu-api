<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a wheel's eight slots. Pays `coins` / `diamonds` (`reward_value` of them) or a store
 * cosmetic — then `reward_type` is the {@see StoreItem} type and `duration_days` how long it
 * lasts (NULL = permanent). `weight` is the slot's share of the draw; 0 never lands.
 */
class LuckyWheelPrize extends Model
{
    public const CURRENCY_TYPES = ['coins', 'diamonds'];

    protected $fillable = [
        'lucky_wheel_id', 'position', 'label', 'reward_type', 'reward_value',
        'store_item_id', 'duration_days', 'icon_url', 'weight', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'position'      => 'integer',
            'reward_value'  => 'integer',
            'duration_days' => 'integer',
            'weight'        => 'integer',
            'is_active'     => 'boolean',
        ];
    }

    public static function types(): array
    {
        return [...self::CURRENCY_TYPES, ...StoreItem::TYPES];
    }

    public static function isItemType(?string $type): bool
    {
        return in_array($type, StoreItem::TYPES, true);
    }

    public function wheel(): BelongsTo
    {
        return $this->belongsTo(LuckyWheel::class, 'lucky_wheel_id');
    }

    public function storeItem(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class);
    }

    /** Whether landing here can actually deliver something. */
    public function isPayable(): bool
    {
        if (in_array($this->reward_type, self::CURRENCY_TYPES, true)) {
            return $this->reward_value > 0;
        }

        return self::isItemType($this->reward_type) && $this->storeItem?->is_active === true;
    }

    /** In the draw: on, weighted, and able to pay. */
    public function isDrawable(): bool
    {
        return $this->is_active && $this->weight > 0 && $this->isPayable();
    }

    /** What the app shows on the slot when the admin left the label empty. */
    public function displayLabel(): string
    {
        if ($this->label) {
            return $this->label;
        }

        return match (true) {
            $this->reward_type === 'coins'    => number_format($this->reward_value),
            $this->reward_type === 'diamonds' => number_format($this->reward_value).' 💎',
            default                           => $this->storeItem?->name ?? 'Prize',
        };
    }

    /** Coin value of the slot for the payout-rate estimate; cosmetics count at their store price. */
    public function coinValue(): int
    {
        return match (true) {
            $this->reward_type === 'coins' => (int) $this->reward_value,
            self::isItemType($this->reward_type) => (int) ($this->storeItem?->coin_price ?? 0),
            default => 0,
        };
    }
}
