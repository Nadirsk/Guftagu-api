<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §5.4 — A.7c. Integer basis points, never a float percentage.
class CommissionSlab extends Model
{
    public const APPLIES_TO = ['platform', 'agency', 'host'];
    public const METRICS = ['diamonds_earned', 'coins_spent'];

    protected $fillable = [
        'applies_to', 'agency_id', 'metric', 'min_value', 'max_value',
        'percentage_bp', 'effective_from', 'effective_to', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'min_value'      => 'integer',
            'max_value'      => 'integer',
            'percentage_bp'  => 'integer',
            'effective_from' => 'datetime',
            'effective_to'   => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    /** 1250 bp → 12.5%. Display only; the integer is the truth. */
    public function percent(): float
    {
        return $this->percentage_bp / 100;
    }

    /** Applies commission to an integer amount, rounding down. */
    public function applyTo(int $amount): int
    {
        return intdiv($amount * $this->percentage_bp, 10000);
    }
}
