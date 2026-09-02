<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminMfaChallenge;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Local-development conveniences. The routes are registered inside an
 * `app()->environment('local')` guard in routes/api.php, so outside local they do not
 * exist at all — this is not a permission check that could be misconfigured.
 *
 * Why this exists: MFA is on for Super Admin and Admin, so testing anything in Swagger
 * means fetching a 6-digit code. The OTP is stored bcrypt-hashed and cannot be read back,
 * so this parses the code out of the mail log (MAIL_MAILER=log) instead of weakening how
 * OTPs are stored.
 */
class DevHelperController extends Controller
{
    /**
     * GET /admin/dev/last-otp
     */
    public function lastOtp(): JsonResponse
    {
        $challenge = AdminMfaChallenge::query()
            ->with('adminUser:id,name,email')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        $otp = $this->otpFromMailLog();

        if ($challenge === null && $otp === null) {
            return ApiResponse::error(
                'NOT_FOUND',
                'No pending MFA challenge, and no OTP found in the mail log. Call POST /admin/auth/login first.',
                null,
                404,
            );
        }

        return ApiResponse::success([
            'otp'          => $otp,
            'challenge_id' => $challenge?->id,
            'purpose'      => $challenge?->purpose,
            'for'          => $challenge?->adminUser?->email,
            'expires_at'   => $challenge?->expires_at?->toIso8601ZuluString(),
            'attempts'     => $challenge?->attempts,
            'source'       => 'storage/logs/laravel.log (MAIL_MAILER=log)',
            'note'         => $otp === null
                ? 'A challenge is pending but no code was found in the log — check MAIL_MAILER=log.'
                : 'Paste challenge_id and otp into POST /admin/auth/mfa/verify.',
        ]);
    }

    /**
     * The most recent 6-digit code in the mail log.
     *
     * The markdown mailable renders the OTP as its own heading, so the text part of the
     * logged message contains a line like `# 123456`. Only the tail of the file is read —
     * the log grows without bound and the answer is always at the end.
     */
    protected function otpFromMailLog(): ?string
    {
        $path = storage_path('logs/laravel.log');

        if (! is_readable($path)) {
            return null;
        }

        $size  = filesize($path);
        $bytes = 200_000;

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        if ($size > $bytes) {
            fseek($handle, -$bytes, SEEK_END);
        }

        $tail = stream_get_contents($handle);
        fclose($handle);

        if ($tail === false) {
            return null;
        }

        // Prefer the heading form the template produces; fall back to any standalone
        // 6-digit run so a template tweak does not silently break this helper.
        if (preg_match_all('/^#\s*(\d{6})\s*$/m', $tail, $m) === 1 || ! empty($m[1])) {
            return end($m[1]) ?: null;
        }

        if (preg_match_all('/\b(\d{6})\b/', $tail, $m2) && ! empty($m2[1])) {
            return end($m2[1]);
        }

        return null;
    }
}
