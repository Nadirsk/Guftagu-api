<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §3.2 — presence history.
class RoomMember extends Model
{
    public const OWNER = 'owner';
    public const CO_HOST = 'co_host';
    public const SPEAKER = 'speaker';
    public const LISTENER = 'listener';

    protected $fillable = [
        'room_id', 'user_id', 'role', 'joined_at', 'left_at', 'duration_seconds', 'is_active',
        'hand_raised_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at'      => 'datetime',
            'left_at'        => 'datetime',
            'is_active'      => 'boolean',
            'hand_raised_at' => 'datetime',
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
