<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomRedEnvelopeClaim extends Model
{
    protected $fillable = ['envelope_id', 'user_id', 'amount'];

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(RoomRedEnvelope::class, 'envelope_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
