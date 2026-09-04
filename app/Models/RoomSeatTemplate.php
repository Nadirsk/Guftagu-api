<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A reusable "N seats, these ones VIP" layout — see the creating migration for why this
 * is separate from a room's own `seat_count`.
 */
class RoomSeatTemplate extends Model
{
    protected $fillable = ['name', 'total_seats', 'vip_positions', 'is_active'];

    protected function casts(): array
    {
        return [
            'total_seats'   => 'integer',
            'vip_positions' => 'array',
            'is_active'     => 'boolean',
        ];
    }

    public function vipSeatCount(): int
    {
        return count($this->vip_positions ?? []);
    }
}
