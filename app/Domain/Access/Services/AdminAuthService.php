<?php

namespace App\Domain\Access\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\SettingsRepository;
use App\Mail\AdminOtpMail;
use App\Models\AdminLoginAttempt;
use App\Models\AdminMfaChallenge;
use App\Models\AdminUser;
use App\Http\Middleware\EnforceIdleTimeout;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * GFT-002 / GFT-003 — admin login, lockout and the email-OTP MFA challenge.
 *
 * The important invariant, from A.1a: when MFA applies, **no access token exists** until
 * the OTP is verified. The challenge row is the only thing login returns.
 */
class AdminAuthService
{
    public function __construct(
        protected SettingsRepository $settings,
        protected AuditLogger $audit,
        protected MfaReauthGate $reauth,
    ) {
    }

    // ------------------------------------------------------------------- lockout

    public function isLockedOut(string $email): bool
    {
        return $this->lockedUntil($email) !== null;
    }

    /**
     * The moment a lockout lifts, or null if the account is not locked.
     *
     * Counts only the failures since the password was last verified — including the
     * MFA-pending case, recorded as `password_ok_mfa_pending`. The lockout guards the
     * password, so proving the password resets it; the OTP stage has its own limits.
     */
    public function lockedUntil(string $email): ?\Illuminate\Support\Carbon
    {
        $max     = $this->settings->int('security.max_login_attempts', 5);
        $minutes = $this->settings->int('security.lockout_minutes', 15);

        // Compare by id, not created_at: attempts land within the same second, and a
        // timestamp comparison then either keeps or drops the whole batch. The id is
        // monotonic, so "since the last success" is unambiguous.
        $lastSuccessId = AdminLoginAttempt::query()
            ->where('email', $email)
            ->where('successful', true)
            ->max('id');

        $failures = AdminLoginAttempt::query()
            ->where('email', $email)
            ->where('successful', false)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->when($lastSuccessId !== null, fn ($q) => $q->where('id', '>', $lastSuccessId))
            ->orderByDesc('id')
            ->limit($max)
            ->get(['created_at']);

        if ($failures->count() < $max) {
            return null;
        }

        // Locked for `minutes` from the most recent failure.
        $until = $failures->first()->created_at->addMinutes($minutes);

        return $until->isFuture() ? $until : null;
    }

    public function recordAttempt(string $email, bool $successful, ?string $reason = null): void
    {
        AdminLoginAttempt::create([
            'email'      => $email,
            'ip'         => request()->ip(),
            'successful' => $successful,
            'reason'     => $reason,
            'user_agent' => Str::limit(request()->userAgent() ?? '', 490),
        ]);
    }

    // ----------------------------------------------------------------------- MFA

    /**
     * A.1d — the role policy governs, and a per-account opt-in can only add to it.
     * Disabling 2FA for the Moderator role therefore stops challenges for moderators
     * who have not individually opted in.
     */
    public function mfaRequiredFor(AdminUser $admin): bool
    {
        $roleKey = $admin->roleKey();

        $byRole = $roleKey !== null
            && $this->settings->bool("security.mfa_required.{$roleKey}", false);

        return $byRole || (bool) $admin->mfa_enabled;
    }

    /**
     * The code that goes into the next challenge.
     *
     * In local development this can be pinned to a fixed value so click-testing does not
     * require reading the mail log on every sign-in. The environment is checked FIRST and
     * independently of the configured value, so the knob is inert anywhere but local —
     * setting it on a deployed box changes nothing.
     */
    protected function nextOtp(): string
    {
        if (app()->environment('local')) {
            $fixed = config('guftagu.admin_mfa.static_otp');

            if (is_string($fixed) && preg_match('/^\d{6}$/', $fixed) === 1) {
                // Loud on purpose: an operator reading logs should never have to wonder
                // whether MFA was real for a given sign-in.
                Log::warning('Admin MFA issued the fixed local OTP — this is local-only.');

                return $fixed;
            }
        }

        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function issueChallenge(AdminUser $admin, string $purpose = 'login', bool $rememberDevice = false): AdminMfaChallenge
    {
        $ttl = $this->settings->int('security.mfa_otp_ttl_minutes', 10);
        $otp = $this->nextOtp();

        // Any older challenge for the same purpose is spent — a new code invalidates the
        // previous one, so two emails in flight cannot both be valid.
        AdminMfaChallenge::query()
            ->where('admin_user_id', $admin->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $challenge = AdminMfaChallenge::create([
            'admin_user_id'   => $admin->id,
            'otp_hash'        => Hash::make($otp),
            'purpose'         => $purpose,
            'expires_at'      => now()->addMinutes($ttl),
            'ip'              => request()->ip(),
            'remember_device' => $rememberDevice,
        ]);

        Mail::to($admin->email)->send(new AdminOtpMail($admin, $otp, $ttl, $purpose));

        return $challenge;
    }

    /**
     * @return array{ok: bool, challenge?: AdminMfaChallenge, error?: string, attempts_left?: int}
     */
    public function verifyChallenge(string $challengeId, string $otp, string $purpose = 'login'): array
    {
        $challenge = AdminMfaChallenge::query()
            ->where('id', $challengeId)
            ->where('purpose', $purpose)
            ->first();

        if ($challenge === null) {
            return ['ok' => false, 'error' => 'invalid_challenge'];
        }

        if ($challenge->isConsumed()) {
            return ['ok' => false, 'error' => 'challenge_used'];
        }

        if ($challenge->isExpired()) {
            return ['ok' => false, 'error' => 'challenge_expired'];
        }

        $max = $this->settings->int('security.mfa_max_attempts', 5);

        if ($challenge->attempts >= $max) {
            $challenge->update(['consumed_at' => now()]);

            return ['ok' => false, 'error' => 'too_many_attempts'];
        }

        // Count the attempt before checking, so a crash mid-verify cannot give a free try.
        $challenge->increment('attempts');

        if (! Hash::check($otp, $challenge->otp_hash)) {
            return [
                'ok'            => false,
                'error'         => 'invalid_otp',
                'attempts_left' => max(0, $max - $challenge->attempts),
            ];
        }

        $challenge->update(['consumed_at' => now()]);

        return ['ok' => true, 'challenge' => $challenge];
    }

    // --------------------------------------------------------------------- token

    /**
     * @return array{token: string, expires_at: string, idle_timeout_minutes: int}
     */
    public function issueToken(AdminUser $admin, ?string $deviceName = null): array
    {
        $expiration = (int) config('sanctum.expiration', 1440);

        $token = $admin->createToken(
            $deviceName ?: 'admin-panel',
            ['admin'],
            now()->addMinutes($expiration),
        );

        $idle = $admin->sessionTimeoutMinutes();

        // Start the idle window now; EnforceIdleTimeout slides it on each request.
        EnforceIdleTimeout::touch($token->accessToken->id, $idle);

        $admin->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->save();

        return [
            'token'                => $token->plainTextToken,
            'expires_at'           => now()->addMinutes($expiration)->toIso8601ZuluString(),
            'idle_timeout_minutes' => $idle,
        ];
    }
}
