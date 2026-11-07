<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One settled 7-day round of a Weekly Star campaign — who placed, and when they were paid. */
class EventRound extends Model
{
    protected $fillable = ['event_id', 'period_start', 'period_end', 'standings', 'granted', 'settled_at', 'settled_by'];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end'   => 'datetime',
            'standings'    => 'array',
            'granted'      => 'integer',
            'settled_at'   => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
