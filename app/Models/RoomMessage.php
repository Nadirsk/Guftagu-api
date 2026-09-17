<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** D.2d — in-room chat (docs/02 §3.2, room_messages). */
class RoomMessage extends Model
{
    use HasUuids;

    protected $fillable = ['uuid', 'room_id', 'user_id', 'body'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getIncrementing(): bool
    {
        return true;
    }

    public function getKeyType(): string
    {
        return 'int';
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
