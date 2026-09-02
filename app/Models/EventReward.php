<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §9 — A.9b. A band of ranks sharing one reward.
class EventReward extends Model
{
    public const TYPES = ['coins', 'diamonds', 'frame', 'badge', 'vip_days'];

    protected $fillable = [
        'event_id', 'rank_from', 'rank_to', 'reward_type',
        'reward_value', 'quantity', 'claimed_count',
    ];

    protected function casts(): array
    {
        return [
            'rank_from'     => 'integer',
            'rank_to'       => 'integer',
            'reward_value'  => 'integer',
            'quantity'      => 'integer',
            'claimed_count' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function covers(int $rank): bool
    {
        return $rank >= $this->rank_from && $rank <= $this->rank_to;
    }

    /** NULL quantity means "as many as the band holds". */
    public function hasCapacity(): bool
    {
        return $this->quantity === null || $this->claimed_count < $this->quantity;
    }
}
