<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §8 — A.9d. Unique per (snapshot, user), which is what makes re-runs safe.
class RankingRewardPayout extends Model
{
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const FAILED = 'failed';

    protected $fillable = [
        'snapshot_id', 'user_id', 'reward_type', 'reward_value',
        'status', 'paid_at', 'transaction_id', 'error',
    ];

    protected function casts(): array
    {
        return ['reward_value' => 'integer', 'paid_at' => 'datetime'];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(LeaderboardSnapshot::class, 'snapshot_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
