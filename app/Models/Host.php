<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

// docs/02 §10 — A.8a.
class Host extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const SUSPENDED = 'suspended';
    public const REJECTED = 'rejected';
    public const LEFT = 'left';

    public const STATUSES = [self::PENDING, self::APPROVED, self::SUSPENDED, self::REJECTED, self::LEFT];

    protected $fillable = [
        'user_id', 'agency_id', 'status', 'applied_at', 'approved_by', 'approved_at',
        'tier', 'base_commission_bp', 'contract_start', 'contract_end', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'applied_at'         => 'datetime',
            'approved_at'        => 'datetime',
            'contract_start'     => 'date',
            'contract_end'       => 'date',
            'base_commission_bp' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by');
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(HostEarning::class)->orderByDesc('date');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(HostTarget::class)->orderByDesc('period_start');
    }

    public function giftTargetResults(): HasMany
    {
        return $this->hasMany(HostGiftTargetResult::class)->orderByDesc('period');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    /**
     * Whether the contract covers a given day.
     *
     * Derived, never a stored flag: a contract that ended yesterday has to stop counting
     * today without anybody running a job.
     */
    public function isUnderContract(?Carbon $on = null): bool
    {
        $day = ($on ?? now())->toDateString();

        if ($this->contract_start !== null && $this->contract_start->toDateString() > $day) {
            return false;
        }

        return $this->contract_end === null || $this->contract_end->toDateString() >= $day;
    }
}
