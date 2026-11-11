<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A red packet left in a room: `total_coins` split over `total_count` people, first come first served. */
class RoomRedEnvelope extends Model
{
    protected $fillable = [
        'uuid', 'room_id', 'sender_id', 'total_coins', 'total_count', 'remaining_coins', 'remaining_count', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(RoomRedEnvelopeClaim::class, 'envelope_id');
    }
}
