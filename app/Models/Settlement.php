<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §5.4 / §10 — A.8d, B.2c.
class Settlement extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';
    public const MANAGER_RAISED = 'manager_raised';
    public const ADMIN_APPROVED = 'admin_approved';
    public const PAID = 'paid';
    public const REJECTED = 'rejected';

    public const STATUSES = [
        self::DRAFT, self::MANAGER_RAISED, self::ADMIN_APPROVED, self::PAID, self::REJECTED,
    ];

    protected $fillable = [
        'uuid', 'agency_id', 'period_start', 'period_end', 'gross_diamonds', 'gross_paise',
        'platform_cut_paise', 'agency_cut_paise', 'host_cut_paise', 'net_payable_paise',
        'rate_numerator', 'rate_denominator', 'host_count', 'status', 'raised_by',
        'approved_by', 'approved_at', 'paid_at', 'batch_id', 'notes',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getIncrementing(): bool
    {
        return true;
    }

    public function getKeyType(): string
    {
        return 'int';
    }

    protected function casts(): array
    {
        return [
            'period_start'       => 'date',
            'period_end'         => 'date',
            'gross_diamonds'     => 'integer',
            'gross_paise'        => 'integer',
            'platform_cut_paise' => 'integer',
            'agency_cut_paise'   => 'integer',
            'host_cut_paise'     => 'integer',
            'net_payable_paise'  => 'integer',
            'rate_numerator'     => 'integer',
            'rate_denominator'   => 'integer',
            'host_count'         => 'integer',
            'approved_at'        => 'datetime',
            'paid_at'            => 'datetime',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'batch_id');
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'raised_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by');
    }

    /** Only a draft may be regenerated; anything beyond it has been acted on by a person. */
    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    /**
     * The three cuts must add back to gross, exactly. Asserted rather than assumed: a
     * rounding leak in a split is invisible until an agency queries the number.
     */
    public function splitsBalance(): bool
    {
        return $this->platform_cut_paise + $this->agency_cut_paise + $this->host_cut_paise
            === $this->gross_paise;
    }
}
