<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Services\AdminAuthService;
use App\Domain\Access\Services\MfaReauthGate;
use App\Domain\Access\Services\PermissionResolver;
use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\SettingsRepository;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Models\AdminUser;
use App\Models\Role;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Epic A.1 — admin authentication. docs/03 §9.
 */
class AdminAuthController extends Controller
{
    public function __construct(
        protected AdminAuthService $auth,
        protected PermissionResolver $resolver,
        protected AuditLogger $audit,
        protected SettingsRepository $settings,
        protected MfaReauthGate $reauth,
    ) {
    }

    /**
     * POST /admin/auth/login — A.1a.
     *
     * Returns a challenge when MFA applies, a token when it does not. Never both.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'           => ['required', 'string', 'email:filter', 'max:191'],
            'password'        => ['required', 'string'],
            'remember_device' => ['sometimes', 'boolean'],
            'device_name'     => ['sometimes', 'string', 'max:100'],
        ]);

        $email = strtolower(trim($data['email']));

        // A.1a — lockout is checked before the password, so a locked account cannot be
        // used as a password oracle.
        if ($lockedUntil = $this->auth->lockedUntil($email)) {
            $this->auth->recordAttempt($email, false, 'locked');

            $this->audit->log(null, 'admin.login_locked', 'access', AdminUser::class, null, null, ['email' => $email]);

            return ApiResponse::error(
                'ACCOUNT_LOCKED',
                'Too many failed attempts. Try again later.',
                ['locked_until' => $lockedUntil->toIso8601ZuluString()],
                423,
            // Retry-After must be whole seconds — Carbon 3 returns a float here.
            )->header('Retry-After', (string) max(1, (int) ceil(now()->diffInSeconds($lockedUntil))));
        }

        $admin = AdminUser::query()->with('role')->where('email', $email)->first();

        // One message and one status for "no such account" and "wrong password" —
        // enumeration is not a feature.
        if ($admin === null || ! Hash::check($data['password'], $admin->password)) {
            $this->auth->recordAttempt($email, false, 'bad_password');

            $this->audit->log($admin, 'admin.login_failed', 'access', AdminUser::class, $admin?->id, null, ['email' => $email]);

            return ApiResponse::error('UNAUTHENTICATED', 'Those credentials do not match our records', null, 401);
        }

        if (! $admin->isActive()) {
            $this->auth->recordAttempt($email, false, 'suspended');

            return ApiResponse::error('FORBIDDEN', 'This account has been suspended', null, 403);
        }

        if ($this->auth->mfaRequiredFor($admin)) {
            $challenge = $this->auth->issueChallenge($admin, 'login', (bool) ($data['remember_device'] ?? false));

            // The lockout exists to stop password guessing, so a verified password clears
            // the streak even though this is not yet a login — otherwise a user who
            // mistyped a few times and then got it right stays one slip from a lockout.
            // The distinct reason keeps the row honest, and audit_logs still separates
            // `admin.mfa_challenged` from `admin.login_mfa`.
            $this->auth->recordAttempt($email, true, 'password_ok_mfa_pending');

            $this->audit->log($admin, 'admin.mfa_challenged', 'access', AdminUser::class, $admin->id);

            return ApiResponse::success([
                'mfa_required' => true,
                'challenge_id' => $challenge->id,
                'expires_at'   => $challenge->expires_at->toIso8601ZuluString(),
                'sent_to'      => $this->maskEmail($admin->email),
            ], 'Enter the code we emailed you');
        }

        $this->auth->recordAttempt($email, true);

        $token = $this->auth->issueToken($admin, $data['device_name'] ?? null);

        $this->audit->log($admin, 'admin.login', 'access', AdminUser::class, $admin->id);

        return ApiResponse::success([
            'mfa_required' => false,
            ...$token,
            'admin'        => $this->profilePayload($admin),
        ], 'Signed in');
    }

    /**
     * POST /admin/auth/mfa/verify — A.1a.
     */
    public function verifyMfa(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string', 'uuid'],
            'otp'          => ['required', 'string', 'regex:/^\d{6}$/'],
            'device_name'  => ['sometimes', 'string', 'max:100'],
        ]);

        $result = $this->auth->verifyChallenge($data['challenge_id'], $data['otp'], 'login');

        if (! $result['ok']) {
            return $this->mfaFailure($result);
        }

        $admin = $result['challenge']->adminUser()->with('role')->first();

        if ($admin === null || ! $admin->isActive()) {
            return ApiResponse::error('FORBIDDEN', 'This account is not available', null, 403);
        }

        $this->auth->recordAttempt($admin->email, true);

        $token = $this->auth->issueToken($admin, $data['device_name'] ?? null);

        $this->audit->log($admin, 'admin.login_mfa', 'access', AdminUser::class, $admin->id);

        return ApiResponse::success([
            ...$token,
            'admin' => $this->profilePayload($admin),
        ], 'Signed in');
    }

    /**
     * POST /admin/auth/mfa/reauth — GFT-122 step 1: ask for a fresh code.
     */
    public function requestReauth(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (! $this->reauth->isRequired()) {
            return ApiResponse::success(['reauth_required' => false], 'Re-authentication is not enabled');
        }

        $challenge = $this->auth->issueChallenge($admin, 'reauth');

        return ApiResponse::success([
            'reauth_required' => true,
            'challenge_id'    => $challenge->id,
            'expires_at'      => $challenge->expires_at->toIso8601ZuluString(),
            'sent_to'         => $this->maskEmail($admin->email),
        ], 'Enter the code we emailed you to confirm this action');
    }

    /**
     * POST /admin/auth/mfa/reauth/verify — GFT-122 step 2.
     */
    public function verifyReauth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string', 'uuid'],
            'otp'          => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $admin  = $request->user();
        $result = $this->auth->verifyChallenge($data['challenge_id'], $data['otp'], 'reauth');

        if (! $result['ok']) {
            return $this->mfaFailure($result);
        }

        // The challenge must belong to the caller — otherwise one admin could satisfy
        // another's re-auth with a code emailed to themselves.
        if ((int) $result['challenge']->admin_user_id !== (int) $admin->id) {
            return ApiResponse::error('FORBIDDEN', 'That challenge does not belong to you', null, 403);
        }

        $this->reauth->markSatisfied($admin);

        $this->audit->log($admin, 'admin.mfa_reauth', 'access', AdminUser::class, $admin->id);

        return ApiResponse::success([
            'confirmed_for_minutes' => $this->reauth->expiresInMinutes(),
        ], 'Confirmed');
    }

    /**
     * GET /admin/auth/me — "the panel renders from this" (docs/03 §9).
     */
    public function me(Request $request): JsonResponse
    {
        $admin = $request->user()->load('role');

        return ApiResponse::success([
            'admin'       => $this->profilePayload($admin),
            'permissions' => $this->resolver->effectiveFor($admin)->values(),
            'session'     => [
                'idle_timeout_minutes' => $admin->sessionTimeoutMinutes(),
                'reauth_satisfied'     => $this->reauth->isSatisfied($admin),
            ],
        ]);
    }

    /**
     * PATCH /admin/auth/profile — A.1b.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $admin = $request->user();

        $data = $request->validate([
            'name'       => ['sometimes', 'string', 'max:150'],
            'phone'      => ['sometimes', 'nullable', 'string', 'max:20'],
            'avatar_url' => ['sometimes', 'nullable', 'url', 'max:500'],
        ]);

        $before = $admin->only(['name', 'phone', 'avatar_url']);

        $admin->fill($data)->save();

        $this->audit->log($admin, 'admin.profile_update', 'access', AdminUser::class, $admin->id, $before, $data);

        return ApiResponse::success($this->profilePayload($admin->fresh('role')), 'Profile updated');
    }

    /**
     * POST /admin/auth/password — A.1b.
     *
     * Acceptance: without the current password it is a 422; with it, all OTHER device
     * tokens are revoked.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $admin = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:12', 'confirmed', 'different:current_password'],
        ]);

        if (! Hash::check($data['current_password'], $admin->password)) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Validation failed',
                ['current_password' => ['The current password is incorrect.']],
                422,
            );
        }

        $admin->update(['password' => $data['password']]);   // 'hashed' cast, bcrypt cost 12

        $currentId = $request->user()->currentAccessToken()->id;

        // Revoke every other session: a password change is how you recover from a
        // compromise, so the attacker's token must not survive it.
        $admin->tokens()->where('id', '!=', $currentId)->get()->each(function ($token) {
            EnforceIdleTimeout::forget($token->id);
            $token->delete();
        });

        $this->audit->log($admin, 'admin.password_change', 'access', AdminUser::class, $admin->id);

        return ApiResponse::success(['other_sessions_revoked' => true], 'Password changed');
    }

    /**
     * POST /admin/auth/logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $admin = $request->user();
        $token = $admin->currentAccessToken();

        EnforceIdleTimeout::forget($token->id);
        $this->reauth->clear($admin);
        $token->delete();

        $this->audit->log($admin, 'admin.logout', 'access', AdminUser::class, $admin->id);

        return ApiResponse::success(null, 'Signed out');
    }

    /**
     * POST /admin/auth/mfa/toggle/{roleKey} — A.1d.
     */
    public function toggleRoleMfa(Request $request, string $roleKey): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $validator = validator(['role' => $roleKey], [
            'role' => ['required', Rule::exists('roles', 'key')],
        ]);

        $validator->validate();

        $key    = "security.mfa_required.{$roleKey}";
        $before = $this->settings->bool($key, false);

        $this->settings->set($key, (bool) $data['enabled'], $request->user()->id);

        $this->audit->log(
            $request->user(),
            'settings.mfa_toggle',
            'settings',
            Role::class,
            null,
            ['role' => $roleKey, 'enabled' => $before],
            ['role' => $roleKey, 'enabled' => (bool) $data['enabled']],
        );

        return ApiResponse::success([
            'role'         => $roleKey,
            'mfa_required' => (bool) $data['enabled'],
        ], '2FA policy updated');
    }

    /**
     * PATCH /admin/settings/session-timeout — A.1c.
     */
    public function updateSessionTimeout(Request $request): JsonResponse
    {
        $data = $request->validate([
            // 0 disables idle expiry; the upper bound stops a typo becoming a week-long session.
            'minutes' => ['required', 'integer', 'min:0', 'max:1440'],
        ]);

        $before = $this->settings->int('security.session_timeout_minutes', 60);

        $this->settings->set('security.session_timeout_minutes', (int) $data['minutes'], $request->user()->id);

        $this->audit->log(
            $request->user(),
            'settings.session_timeout',
            'settings',
            null,
            null,
            ['minutes' => $before],
            ['minutes' => (int) $data['minutes']],
        );

        return ApiResponse::success(['session_timeout_minutes' => (int) $data['minutes']], 'Session timeout updated');
    }

    // ------------------------------------------------------------------ internals

    protected function mfaFailure(array $result): JsonResponse
    {
        return match ($result['error']) {
            'invalid_challenge' => ApiResponse::error('NOT_FOUND', 'That challenge does not exist', null, 404),
            'challenge_used'    => ApiResponse::error('BAD_REQUEST', 'That code has already been used', null, 400),
            'challenge_expired' => ApiResponse::error('BAD_REQUEST', 'That code has expired — sign in again', null, 400),
            'too_many_attempts' => ApiResponse::error('RATE_LIMITED', 'Too many incorrect codes — sign in again', null, 429),
            default             => ApiResponse::error(
                'UNAUTHENTICATED',
                'That code is not correct',
                ['attempts_left' => $result['attempts_left'] ?? null],
                401,
            ),
        };
    }

    protected function profilePayload(AdminUser $admin): array
    {
        return [
            'id'            => $admin->id,
            'name'          => $admin->name,
            'email'         => $admin->email,
            'phone'         => $admin->phone,
            'avatar_url'    => $admin->avatar_url,
            'status'        => $admin->status,
            'mfa_enabled'   => (bool) $admin->mfa_enabled,
            'role'          => $admin->role === null ? null : [
                'id'   => $admin->role->id,
                'key'  => $admin->role->key,
                'name' => $admin->role->name,
            ],
            'last_login_at' => $admin->last_login_at?->toIso8601ZuluString(),
        ];
    }

    protected function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $visible = mb_substr($local, 0, 2);
        $masked  = str_repeat('•', max(1, mb_strlen($local) - 2));

        return $visible.$masked.'@'.$domain;
    }
}
