<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §5.3 — a rational rate, effective-dated. Never edited; superseded.
class ConversionRate extends Model
{
    protected $fillable = [
        'key', 'rate_numerator', 'rate_denominator',
        'effective_from', 'effective_to', 'set_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'rate_numerator'   => 'integer',
            'rate_denominator' => 'integer',
            'effective_from'   => 'datetime',
            'effective_to'     => 'datetime',
        ];
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'set_by');
    }

    public function isInForce(): bool
    {
        return $this->effective_from->isPast()
            && ($this->effective_to === null || $this->effective_to->isFuture());
    }

    /**
     * For display only — the fraction is the truth.
     *
     * NOT named asDecimal(): Eloquent's Model already declares an asDecimal() used for
     * attribute casting, and redeclaring it with a different signature is a fatal error.
     * for attribute casting, and overriding it with a different signature is a fatal error.
     */
    public function decimalValue(): float
    {
        return $this->rate_denominator === 0 ? 0.0 : $this->rate_numerator / $this->rate_denominator;
    }
}
