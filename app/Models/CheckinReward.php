<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Epic D.7c — one slot of the 7-day check-in ladder, admin-configured.
 */
class CheckinReward extends Model
{
    /** Narrower than EventReward::TYPES for now — cosmetic types need an inventory table
     *  that does not exist yet. */
    public const TYPES = ['coins', 'diamonds'];

    protected $fillable = ['streak_day', 'reward_type', 'reward_value', 'icon_url', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
