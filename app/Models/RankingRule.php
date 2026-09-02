<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// docs/02 §8 — A.9c.
class RankingRule extends Model
{
    public const BOARD_TYPES = ['wealth', 'charm', 'room', 'agency'];
    public const PERIODS = ['daily', 'weekly', 'monthly', 'all_time'];
    public const METRICS = ['coins_spent', 'diamonds_earned'];

    protected $fillable = [
        'key', 'board_type', 'period', 'metric',
        'min_threshold', 'top_n', 'reset_cron', 'is_active',
    ];

    protected function casts(): array
    {
        return ['min_threshold' => 'integer', 'top_n' => 'integer', 'is_active' => 'boolean'];
    }
}
