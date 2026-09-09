<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Epic D.1a — a single OTP challenge for either channel (email/phone) and either purpose
 * (auth, reset_password). See {@see \App\Domain\Onboarding\Services\OtpService}.
 */
class OtpVerification extends Model
{
    public const PURPOSE_AUTH = 'auth';
    public const PURPOSE_RESET_PASSWORD = 'reset_password';

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_PHONE = 'phone';

    protected $fillable = [
        'channel', 'identifier_hash', 'purpose', 'otp_hash', 'attempts', 'expires_at', 'consumed_at', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'  => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
