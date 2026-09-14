<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's score for one period of a threshold-tier campaign event — e.g. "My Monthly
 * Recharge". Recomputed by {@see \App\Domain\Events\EventCampaignService}, not written by
 * the user directly, so it can never drift from the ledger it is summed from.
 */
class EventProgress extends Model
{
    protected $fillable = ['event_id', 'user_id', 'period_type', 'period_start', 'period_end', 'score'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end'   => 'date',
            'score'        => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
