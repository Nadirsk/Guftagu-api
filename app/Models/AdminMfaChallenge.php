<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GFT-003 — an email-OTP challenge issued at login, before any token exists.
 * Also used for MFA re-entry when granting a high-risk permission (GFT-122).
 */
class AdminMfaChallenge extends Model
{
    use HasUuids;

    protected $fillable = [
        'admin_user_id', 'otp_hash', 'purpose', 'attempts',
        'expires_at', 'consumed_at', 'ip', 'remember_device',
    ];

    protected $hidden = ['otp_hash'];

    protected function casts(): array
    {
        return [
            'expires_at'      => 'datetime',
            'consumed_at'     => 'datetime',
            'remember_device' => 'boolean',
            'attempts'        => 'integer',
        ];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isUsable(): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired();
    }
}
