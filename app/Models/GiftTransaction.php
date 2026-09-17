<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One gift send, at the individual-transaction level — the source of truth a windowed,
 * gift-scoped campaign event's progress metric (`gift_value`/`gift_count`) sums from.
 * Immutable, same as `coin_transactions`/`diamond_transactions`.
 */
class GiftTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'sender_id', 'receiver_id', 'room_id', 'gift_id', 'gift_category_id',
        'quantity', 'coin_value', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'quantity'   => 'integer',
            'coin_value' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function gift(): BelongsTo
    {
        return $this->belongsTo(Gift::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GiftCategory::class, 'gift_category_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
