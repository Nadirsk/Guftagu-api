<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A viewer's private call with the host of a live video. */
class RoomCall extends Model
{
    public const APPLYING = 'applying';
    public const ONGOING = 'ongoing';
    public const ENDED = 'ended';
    public const CANCELLED = 'cancelled';
    public const REJECTED = 'rejected';

    protected $fillable = ['room_id', 'caller_id', 'type', 'price', 'status', 'seat_number', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'seat_number' => 'integer', 'started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }
}
