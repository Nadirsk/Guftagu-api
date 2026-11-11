<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/02 §8 — A.9d. One reward a rank range earns.
 *
 * Like {@see CheckinReward}, a row pays currency (`coins` / `diamonds`, amount in
 * `reward_value`) or a store cosmetic: then `reward_type` is the item's {@see StoreItem}
 * type, `store_item_id` names it and `duration_days` is how long it lasts (NULL =
 * permanent). A rank may carry up to MAX_PER_RANK rows — the app's Weekly List card
 * has exactly three slots.
 */
class RankingReward extends Model
{
    public const CURRENCY_TYPES = ['coins', 'diamonds'];

    public const MAX_PER_RANK = 3;

    /** The CP Reward cards hold four slots, the Weekly List cards three. */
    public const MAX_PER_RANK_CP = 4;

    public static function maxPerRank(RankingRule $rule): int
    {
        return $rule->board_type === 'cp' ? self::MAX_PER_RANK_CP : self::MAX_PER_RANK;
    }

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
        'rule_key', 'rank_from', 'rank_to', 'reward_type', 'reward_value', 'store_item_id', 'duration_days', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rank_from'     => 'integer',
            'rank_to'       => 'integer',
            'reward_value'  => 'integer',
            'duration_days' => 'integer',
            'is_active'     => 'boolean',
        ];
    }

    public function storeItem(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class);
    }

    public function covers(int $rank): bool
    {
        return $rank >= $this->rank_from && $rank <= $this->rank_to;
    }

    /** Whether paying this row can actually deliver something. */
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
