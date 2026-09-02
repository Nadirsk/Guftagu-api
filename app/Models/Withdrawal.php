<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** docs/02 §5.4 — A.7b. ⚠ CI-03 supplies the real policy. */
class Withdrawal extends Model
{
    use HasUuids;

    public const PENDING = 'pending';
    public const PENDING_SUPER = 'pending_super_approval';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const PROCESSING = 'processing';
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const REVERTED = 'reverted';

    protected $fillable = [
        'uuid', 'user_id', 'diamonds', 'gross_paise', 'commission_paise', 'tds_paise',
        'net_paise', 'rate_numerator', 'rate_denominator', 'conversion_rate_id',
        'method', 'payout_details', 'status', 'requested_at', 'reviewed_by', 'reviewed_at',
        'second_approved_by', 'second_approved_at', 'rejection_reason',
        'batch_id', 'utr', 'paid_at',
    ];

    protected $hidden = ['payout_details'];

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
            'payout_details'     => 'encrypted',
            'diamonds'           => 'integer',
            'gross_paise'        => 'integer',
            'commission_paise'   => 'integer',
            'tds_paise'          => 'integer',
            'net_paise'          => 'integer',
            'rate_numerator'     => 'integer',
            'rate_denominator'   => 'integer',
            'requested_at'       => 'datetime',
            'reviewed_at'        => 'datetime',
            'second_approved_at' => 'datetime',
            'paid_at'            => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }

    public function secondApprovedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'second_approved_by');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'batch_id');
    }

    /** Still awaiting a decision, in either of the two waiting states. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::PENDING_SUPER], true);
    }

    /**
     * The rate this request was priced at, as it was when raised — not today's.
     * A.7a depends on reading it from here rather than resolving it again.
     */
    public function rateAsDecimal(): float
    {
        return $this->rate_denominator === 0 ? 0.0 : $this->rate_numerator / $this->rate_denominator;
    }
}
