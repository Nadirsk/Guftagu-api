<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §9 — A.9b. Unique per (event, user): one reward each, once.
class EventRewardClaim extends Model
{
    protected $fillable = [
        'event_id', 'user_id', 'reward_id', 'rank', 'status', 'claimed_at', 'transaction_id',
    ];

    protected function casts(): array
    {
        return ['claimed_at' => 'datetime', 'rank' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(EventReward::class, 'reward_id');
    }
}
