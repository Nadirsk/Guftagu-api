<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One spin and what it paid, copied from the slot at the time so later edits leave history alone. */
class LuckyWheelSpin extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'batch', 'user_id', 'lucky_wheel_id', 'prize_id', 'room_id', 'cost', 'position',
        'label', 'reward_type', 'reward_value', 'store_item_id', 'duration_days',
    ];

    protected function casts(): array
    {
        return [
            'cost'          => 'integer',
            'position'      => 'integer',
            'reward_value'  => 'integer',
            'duration_days' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wheel(): BelongsTo
    {
        return $this->belongsTo(LuckyWheel::class, 'lucky_wheel_id');
    }

    public function storeItem(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class);
    }
}
