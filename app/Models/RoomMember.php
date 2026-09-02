<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §3.2 — presence history.
class RoomMember extends Model
{
    protected $fillable = [
        'room_id', 'user_id', 'role', 'joined_at', 'left_at', 'duration_seconds', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at'   => 'datetime',
            'is_active' => 'boolean',
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
}
