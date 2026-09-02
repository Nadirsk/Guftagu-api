<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// GFT-145 — the saved replies a support inbox runs on.
class CannedReply extends Model
{
    protected $fillable = [
        'title', 'category', 'body_en', 'body_hi', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'use_count' => 'integer'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // Which replies actually get used is the only honest guide to which ones are worth
    // keeping, so the counter is a feature rather than a statistic.
    public function recordUse(): void
    {
        $this->increment('use_count');
    }
}
