<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Epic D.7c — one slot of the 7-day check-in ladder, admin-configured.
 *
 * A slot pays either currency (`coins` / `diamonds`, amount in `reward_value`) or a store
 * cosmetic: then `reward_type` is the item's {@see StoreItem} type, `store_item_id` names
 * it and `duration_days` is how long it is granted for (NULL = permanent).
 */
class CheckinReward extends Model
{
    public const CURRENCY_TYPES = ['coins', 'diamonds'];

    /** Currencies plus every cosmetic type the store sells. */
    public static function types(): array
    {
        return [...self::CURRENCY_TYPES, ...StoreItem::TYPES];
    }

    public static function isItemType(?string $type): bool
    {
        return in_array($type, StoreItem::TYPES, true);
    }

    protected $fillable = [
        'streak_day', 'reward_type', 'reward_value', 'store_item_id', 'duration_days', 'icon_url', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active'     => 'boolean',
            'duration_days' => 'integer',
        ];
    }

    public function storeItem(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class);
    }

    /** Whether claiming this slot can actually deliver something. */
    public function isPayable(): bool
    {
        if (in_array($this->reward_type, self::CURRENCY_TYPES, true)) {
            return true;
        }

        return self::isItemType($this->reward_type) && $this->store_item_id !== null && $this->storeItem?->is_active === true;
    }

    /** @return array<string, mixed>|null */
    public function itemPayload(): ?array
    {
        $item = $this->storeItem;

        return $item === null ? null : [
            'id'            => $item->id,
            'type'          => $item->type,
            'name'          => $item->name,
            'image_url'     => $item->image_url,
            'animation_url' => $item->animation_url,
        ];
    }
}
