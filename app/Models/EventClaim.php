<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's claim of one tier's reward bundle for one period — generalises
 * `EventRewardClaim` (which stays rank_from/rank_to-shaped, for `event`/`tournament`/
 * `lucky_draw`) to campaign events. The unique index on
 * (event_tier_id, user_id, period_start) is what makes "once per period" hold even under
 * a concurrent double-claim.
 */
class EventClaim extends Model
{
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const FAILED = 'failed';

    protected $fillable = [
        'event_id', 'event_tier_id', 'user_id', 'period_type',
        'period_start', 'status', 'claimed_at', 'granted',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'claimed_at'   => 'datetime',
            'granted'      => 'array',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(EventTier::class, 'event_tier_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
