<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// docs/02 §3.2 — A.4d. VIP gating is stored here and enforced by the app (A.6 supplies tiers).
class RoomTheme extends Model
{
    protected $fillable = [
        'name', 'background_url', 'preview_url', 'is_premium',
        'required_vip_tier_id', 'coin_price', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_premium' => 'boolean',
            'is_active'  => 'boolean',
            'coin_price' => 'integer',
        ];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'theme_id');
    }
}
