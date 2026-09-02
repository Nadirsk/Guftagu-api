<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// docs/02 §5.3 — A.7a. ⚠ CI-01.
class RechargePackage extends Model
{
    protected $fillable = [
        'name', 'coins', 'bonus_coins', 'price_paise', 'currency',
        'is_first_purchase_only', 'is_active', 'sort_order', 'badge_text',
        'valid_from', 'valid_to',
    ];

    protected function casts(): array
    {
        return [
            'coins'                  => 'integer',
            'bonus_coins'            => 'integer',
            'price_paise'            => 'integer',
            'is_first_purchase_only' => 'boolean',
            'is_active'              => 'boolean',
            'sort_order'             => 'integer',
            'valid_from'             => 'datetime',
            'valid_to'               => 'datetime',
        ];
    }

    /** Sellable right now — window decided in the query, not by a job. */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', now()));
    }

    public function totalCoins(): int
    {
        return $this->coins + $this->bonus_coins;
    }

    /** Paise per coin — the number that makes packages comparable at a glance. */
    public function paisePerCoin(): float
    {
        $total = $this->totalCoins();

        return $total > 0 ? round($this->price_paise / $total, 4) : 0.0;
    }
}
