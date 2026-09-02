<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared behaviour for the coin and diamond ledgers (docs/02 §5.2).
 *
 * The two currencies keep separate tables so they can never be confused in a query, but
 * their shape and rules are identical — hence one base class.
 *
 * Rows are IMMUTABLE (§15 rule 3). The model enforces that rather than trusting callers:
 * an update or delete throws, so "just fix the row" is not reachable by accident. A
 * mistake is corrected with a compensating entry.
 */
abstract class LedgerTransaction extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const CREDIT = 'credit';
    public const DEBIT = 'debit';

    public const TYPE_ADMIN_CREDIT = 'admin_credit';
    public const TYPE_ADMIN_DEBIT = 'admin_debit';

    protected $fillable = [
        'uuid', 'wallet_id', 'user_id', 'direction', 'amount', 'balance_before',
        'balance_after', 'type', 'reference_type', 'reference_id', 'idempotency_key',
        'performed_by', 'note',
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
            'amount'         => 'integer',
            'balance_before' => 'integer',
            'balance_after'  => 'integer',
            'created_at'     => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException(
                'Ledger rows are immutable (docs/02 §15 rule 3). Write a compensating entry instead.'
            );
        });

        static::deleting(function () {
            throw new \LogicException(
                'Ledger rows are immutable (docs/02 §15 rule 3). They are never deleted.'
            );
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'performed_by');
    }

    public function isAdminAdjustment(): bool
    {
        return in_array($this->type, [self::TYPE_ADMIN_CREDIT, self::TYPE_ADMIN_DEBIT], true);
    }

    /** `+1,000` / `−250`, with the sign coming from `direction` as the schema intends. */
    public function signedAmount(): int
    {
        return $this->direction === self::CREDIT ? $this->amount : -$this->amount;
    }
}
