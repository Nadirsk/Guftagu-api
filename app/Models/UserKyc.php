<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §2.3 — A.3b. Document number, PAN and bank account are encrypted at rest.
class UserKyc extends Model
{
    protected $table = 'user_kyc';

    public const PENDING = 'pending';
    public const VERIFIED = 'verified';
    public const REJECTED = 'rejected';

    protected $fillable = [
        'user_id', 'full_name', 'doc_type', 'doc_number', 'doc_front_url', 'doc_back_url',
        'selfie_url', 'pan', 'bank_account', 'ifsc', 'upi_id', 'status',
        'reviewed_by', 'reviewed_at', 'rejection_reason',
    ];

    protected $hidden = ['doc_number', 'pan', 'bank_account'];

    protected function casts(): array
    {
        return [
            'doc_number'   => 'encrypted',
            'pan'          => 'encrypted',
            'bank_account' => 'encrypted',
            'reviewed_at'  => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }

    /** Last four only — enough to match a document without exposing it. */
    public function maskedDocNumber(): ?string
    {
        return $this->doc_number === null
            ? null
            : str_repeat('•', 4).' '.substr($this->doc_number, -4);
    }
}
