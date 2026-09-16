<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §3.2 — D.2b.
class RoomSeat extends Model
{
    protected $fillable = [
        'room_id', 'seat_number', 'user_id', 'is_locked', 'is_vip',
        'is_muted_by_host', 'is_self_muted', 'is_camera_on', 'occupied_at',
    ];

    protected function casts(): array
    {
        return [
            'is_locked'        => 'boolean',
            'is_vip'           => 'boolean',
            'is_muted_by_host' => 'boolean',
            'is_self_muted'    => 'boolean',
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

    /** What every listener actually hears, regardless of which party silenced it. */
    public function isEffectivelyMuted(): bool
    {
        return $this->is_muted_by_host || $this->is_self_muted;
    }
}
