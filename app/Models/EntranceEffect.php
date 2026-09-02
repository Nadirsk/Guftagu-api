<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §6 — A.6d.
class EntranceEffect extends Model
{
    public const TRIGGERS = ['vip_entry', 'big_gift', 'level_up', 'event'];

    protected $fillable = [
        'name', 'animation_url', 'animation_type', 'duration_ms',
        'trigger', 'required_vip_tier_id', 'min_gift_coin_value', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'duration_ms'         => 'integer',
            'min_gift_coin_value' => 'integer',
            'is_active'           => 'boolean',
        ];
    }

    public function requiredVipTier(): BelongsTo
    {
        return $this->belongsTo(VipTier::class, 'required_vip_tier_id');
    }
}
