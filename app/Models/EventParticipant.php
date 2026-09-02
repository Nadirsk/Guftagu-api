<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §9.
class EventParticipant extends Model
{
    protected $fillable = ['event_id', 'user_id', 'joined_at', 'score', 'rank', 'status'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'score' => 'integer', 'rank' => 'integer'];
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
