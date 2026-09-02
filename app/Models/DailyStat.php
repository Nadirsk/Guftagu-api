<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// GFT-018 — one row per day. The dashboard reads only from here, never the ledgers.
class DailyStat extends Model
{
    protected $fillable = [
        'date', 'new_users', 'active_users', 'total_users', 'banned_users',
        'recharge_coins', 'gifting_coins', 'vip_coins', 'other_coins',
        'admin_credit_coins', 'admin_debit_coins', 'diamonds_earned',
        'rooms_opened', 'peak_live_rooms', 'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'date'               => 'date',
            'computed_at'        => 'datetime',
            'new_users'          => 'integer',
            'active_users'       => 'integer',
            'total_users'        => 'integer',
            'banned_users'       => 'integer',
            'recharge_coins'     => 'integer',
            'gifting_coins'      => 'integer',
            'vip_coins'          => 'integer',
            'other_coins'        => 'integer',
            'admin_credit_coins' => 'integer',
            'admin_debit_coins'  => 'integer',
            'diamonds_earned'    => 'integer',
            'rooms_opened'       => 'integer',
            'peak_live_rooms'    => 'integer',
        ];
    }
}
