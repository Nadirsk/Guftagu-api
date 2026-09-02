<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §10 — A.8b, GFT-082/083.
class HostTarget extends Model
{
    public const ACTIVE = 'active';
    public const ACHIEVED = 'achieved';
    public const MISSED = 'missed';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'host_id', 'period_start', 'period_end', 'target_diamonds', 'target_hours',
        'target_days', 'achieved_diamonds', 'achieved_hours', 'achieved_days',
        'achievement_pct', 'incentive_paise', 'incentive_bp', 'status',
        'evaluated_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start'      => 'date',
            'period_end'        => 'date',
            'target_diamonds'   => 'integer',
            'target_hours'      => 'integer',
            'target_days'       => 'integer',
            'achieved_diamonds' => 'integer',
            'achieved_hours'    => 'integer',
            'achieved_days'     => 'integer',
            'achievement_pct'   => 'integer',
            'incentive_paise'   => 'integer',
            'incentive_bp'      => 'integer',
            'evaluated_at'      => 'datetime',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    /** Still running — read off the clock, not off `status`. */
    public function isOpen(): bool
    {
        return $this->status === self::ACTIVE && $this->period_end->endOfDay()->isFuture();
    }

    public function scopeDueForEvaluation(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE)
            ->whereNull('evaluated_at')
            ->whereDate('period_end', '<', now()->toDateString());
    }
}
