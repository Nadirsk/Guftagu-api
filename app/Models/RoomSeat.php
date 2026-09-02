<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §3.2 — D.2b.
class RoomSeat extends Model
{
    protected $fillable = [
        'room_id', 'seat_number', 'user_id', 'is_locked',
        'is_muted_by_host', 'is_camera_on', 'occupied_at',
    ];

    protected function casts(): array
    {
        return [
            'is_locked'        => 'boolean',
            'is_muted_by_host' => 'boolean',
            'is_camera_on'     => 'boolean',
            'occupied_at'      => 'datetime',
            'seat_number'      => 'integer',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOccupied(): bool
    {
        return $this->user_id !== null;
    }
}
