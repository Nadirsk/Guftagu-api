<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Epic D.7c — one claimed day. `reward_type`/`reward_value` are a snapshot of the
 * {@see CheckinReward} slot at claim time, not a live reference to it.
 */
class DailyCheckin extends Model
{
    protected $fillable = ['user_id', 'checkin_date', 'streak_day', 'reward_type', 'reward_value'];

    protected function casts(): array
    {
        return [
            'checkin_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
