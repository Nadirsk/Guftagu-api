<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One rung of the monthly gift-target ladder — see the creating migration for how this
 * differs from `HostTarget`. Both `time_minutes` and `target_coins` must be cleared in a
 * calendar month for a host to achieve this tier.
 */
class GiftTargetPolicy extends Model
{
    protected $fillable = [
        'time_minutes', 'target_coins', 'host_reward_paise', 'agency_reward_paise', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'time_minutes'         => 'integer',
            'target_coins'         => 'integer',
            'host_reward_paise'    => 'integer',
            'agency_reward_paise'  => 'integer',
            'is_active'            => 'boolean',
        ];
    }
}
