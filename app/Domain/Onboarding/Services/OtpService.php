<?php

namespace App\Domain\Onboarding\Services;

use App\Mail\UserOtpMail;
use App\Models\OtpVerification;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Epic D.1a — OTP send/verify for both channels (email, phone) and both purposes
 * (auth = sign-in/registration, reset_password). Mirrors AdminAuthService's MFA challenge
 * handling, keyed on the identifier's hash instead of a challenge id — the mobile contract
 * (docs/03 §3) re-sends `{phone|email}` on verify rather than a challenge id.
 */
class OtpService
{
    public function send(string $channel, string $identifier, string $purpose): void
    {
        $ttl = (int) config('guftagu.user_otp.ttl_minutes', 5);
        $otp = $this->nextOtp();
        $hash = User::hash($identifier);

        // A new code invalidates any other one in flight for the same identifier+purpose.
        OtpVerification::query()
            ->where('identifier_hash', $hash)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        OtpVerification::create([
            'channel'         => $channel,
            'identifier_hash' => $hash,
            'purpose'         => $purpose,
            'otp_hash'        => Hash::make($otp),
            'expires_at'      => now()->addMinutes($ttl),
            'ip'              => request()?->ip(),
        ]);

        if ($channel === OtpVerification::CHANNEL_EMAIL) {
            Mail::to($identifier)->send(new UserOtpMail($otp, $ttl, $purpose));
        } else {
            $this->sendSms($identifier, $otp, $ttl);
        }
    }

    /**
     * @return array{ok: bool, error?: string, attempts_left?: int}
     */
    public function verify(string $channel, string $identifier, string $purpose, string $otp): array
    {
        $hash = User::hash($identifier);

        $record = OtpVerification::query()
            ->where('channel', $channel)
            ->where('identifier_hash', $hash)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($record === null) {
            return ['ok' => false, 'error' => 'invalid_otp'];
        }

        if ($record->isExpired()) {
            return ['ok' => false, 'error' => 'expired'];
        }

        $max = (int) config('guftagu.user_otp.max_attempts', 5);

        if ($record->attempts >= $max) {
            $record->update(['consumed_at' => now()]);

            return ['ok' => false, 'error' => 'too_many_attempts'];
        }

        // Count the attempt before checking, so a crash mid-verify cannot give a free try.
        $record->increment('attempts');

        if (! Hash::check($otp, $record->otp_hash)) {
            return [
                'ok'            => false,
                'error'         => 'invalid_otp',
                'attempts_left' => max(0, $max - $record->attempts),
            ];
        }

        $record->update(['consumed_at' => now()]);

        return ['ok' => true];
    }

    /**
     * The code that goes into the next OTP. In local development this can be pinned to a
     * fixed value, exactly like AdminAuthService::nextOtp — the environment is checked
     * first and independently of the configured value, so this is inert anywhere but local.
     */
    protected function nextOtp(): string
    {
        if (app()->environment('local')) {
            $fixed = config('guftagu.user_otp.static_otp');

            if (is_string($fixed) && preg_match('/^\d{6}$/', $fixed) === 1) {
                Log::warning('User OTP issued the fixed local code — this is local-only.');

                return $fixed;
            }
        }

        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Sends via MSG91 once its credentials are configured; until then, logs the code the
     * same way local email OTPs land in storage/logs/laravel.log via MAIL_MAILER=log.
     */
    protected function sendSms(string $phone, string $otp, int $ttl): void
    {
        $authKey = config('services.msg91.auth_key');

        if (! is_string($authKey) || $authKey === '') {
            Log::warning('Phone OTP has no SMS gateway configured — logging instead of sending.', [
                'phone' => $phone,
                'otp'   => $otp,
                'ttl'   => $ttl,
            ]);

            return;
        }

        try {
            Http::timeout(5)->withHeaders(['authkey' => $authKey])->get('https://control.msg91.com/api/v5/otp', [
                'template_id' => config('services.msg91.template_id'),
                'mobile'      => ltrim($phone, '+'),
                'otp'         => $otp,
                'otp_expiry'  => $ttl,
            ]);
        } catch (\Throwable $e) {
            Log::error('msg91.send_failed', ['message' => $e->getMessage()]);
        }
    }
}
