<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lucky Wheel — one of the two wheels (Classic, Glory), with its spin prices. Its eight
 * slots are {@see LuckyWheelPrize} rows; what people won is in {@see LuckyWheelSpin}.
 */
class LuckyWheel extends Model
{
    public const MODES = ['classic', 'glory'];

    public const SLOTS = 8;

    protected $fillable = ['mode', 'name', 'spin_cost', 'multi_count', 'multi_cost', 'is_active'];

    protected function casts(): array
    {
        return [
            'spin_cost'   => 'integer',
            'multi_count' => 'integer',
            'multi_cost'  => 'integer',
            'is_active'   => 'boolean',
        ];
    }

    public function prizes(): HasMany
    {
        return $this->hasMany(LuckyWheelPrize::class)->orderBy('position');
    }

    public function spins(): HasMany
    {
        return $this->hasMany(LuckyWheelSpin::class);
    }

    /** Coins for `$times` spins: the bundle price for the multi button, the single price otherwise. */
    public function costFor(int $times): int
    {
        return $times === $this->multi_count ? $this->multi_cost : $this->spin_cost * $times;
    }
}
