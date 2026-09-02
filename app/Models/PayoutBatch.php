<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// docs/02 §5.4 — GFT-071.
class PayoutBatch extends Model
{
    protected $fillable = [
        'batch_number', 'type', 'count', 'total_paise', 'status',
        'created_by', 'approved_by', 'approved_at', 'processed_at', 'file_url',
    ];

    protected function casts(): array
    {
        return [
            'count'        => 'integer',
            'total_paise'  => 'integer',
            'approved_at'  => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class, 'batch_id');
    }

    /** A batch holds one kind of payable; `type` says which. */
    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class, 'batch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
