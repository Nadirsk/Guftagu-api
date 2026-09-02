<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §7 — A.6d.
class Frame extends Model
{
    public const SOURCES = ['vip', 'event', 'purchase', 'admin'];

    protected $fillable = [
        'name', 'image_url', 'animation_url', 'source',
        'coin_price', 'required_vip_tier_id', 'is_active',
    ];

    protected function casts(): array
    {
        return ['coin_price' => 'integer', 'is_active' => 'boolean'];
    }

    public function requiredVipTier(): BelongsTo
    {
        return $this->belongsTo(VipTier::class, 'required_vip_tier_id');
    }
}
