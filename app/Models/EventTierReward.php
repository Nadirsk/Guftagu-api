<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a tier's reward bundle — a use of a {@see RewardCatalogItem}, with the
 * amount/duration that particular use grants. The same catalog entry ("Coins") is
 * reused across many tiers with a different `reward_value` each time; a catalog entry
 * bound to a specific VIP tier or store item ("VIP Gold", "700K Frame") is usually
 * reused as-is.
 */
class EventTierReward extends Model
{
    protected $fillable = [
        'event_tier_id', 'reward_catalog_id', 'reward_value',
        'duration_days', 'label', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'reward_value'  => 'integer',
            'duration_days' => 'integer',
            'sort_order'    => 'integer',
        ];
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(EventTier::class, 'event_tier_id');
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(RewardCatalogItem::class, 'reward_catalog_id');
    }

    public function isGrantable(): bool
    {
        return ! ($this->catalog?->isManual() ?? true);
    }
}
