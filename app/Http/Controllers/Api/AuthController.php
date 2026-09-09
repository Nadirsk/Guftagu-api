<?php

namespace App\Http\Controllers\Api;

use App\Domain\Onboarding\Services\OtpService;
use App\Domain\Onboarding\Services\SocialTokenVerifier;
use App\Domain\Onboarding\Services\UserAuthService;
use App\Http\Controllers\Controller;
use App\Models\OtpVerification;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Epic D.1a — mobile sign-in/registration: OTP (email or phone), password (email or
 * phone), and social (Google/Facebook). docs/03 §3 spec'd phone-OTP + Google/Apple only;
 * this widens it to match the actual onboarding screens (email, password, Facebook).
 *
 * Every path that can create an account funnels through
 * {@see UserAuthService::createUser()}, so `guftagu_id`/`agora_uid` provisioning and the
 * "link, don't duplicate" rule (D.1a) only live in one place.
 */
class AuthController extends Controller
{
    public function __construct(
        protected OtpService $otp,
        protected UserAuthService $auth,
        protected SocialTokenVerifier $socialVerifier,
    ) {
    }

    /**
     * POST /auth/otp/send — D.1a. Works for a phone or email that has never signed up
     * before; verifying it is what registers the account.
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $data = $this->validateIdentifier($request, ['purpose' => ['required', 'string', Rule::in(['auth', 'reset_password'])]]);

        $identifier = $this->resolveIdentifier($data);

        if ($data['purpose'] === OtpVerification::PURPOSE_RESET_PASSWORD) {
            // Enumeration-safe: the response is identical whether or not the account
            // exists — only whether a code actually goes out differs.
            if ($this->auth->findByIdentifier($data['channel'], $identifier) !== null) {
                $this->otp->send($data['channel'], $identifier, $data['purpose']);
            }
        } else {
            $this->otp->send($data['channel'], $identifier, $data['purpose']);
        }

        return ApiResponse::success([
            'channel'              => $data['channel'],
            'expires_in_minutes'   => (int) config('guftagu.user_otp.ttl_minutes', 5),
        ], 'Verification code sent');
    }

    /**
     * POST /auth/otp/verify — D.1a. Logs into the existing account when the phone/email
     * is already registered, or creates one (`is_new_user: true`) when it is not.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $this->validateIdentifier($request, [
            'otp'    => ['required', 'string', 'regex:/^\d{6}$/'],
            'device' => ['required', 'array'],
            ...$this->deviceRules(),
        ]);

        $identifier = $this->resolveIdentifier($data);

        $result = $this->otp->verify($data['channel'], $identifier, OtpVerification::PURPOSE_AUTH, $data['otp']);

        if (! $result['ok']) {
            return $this->otpFailure($result);
        }

        $outcome = $this->auth->loginOrRegisterByOtp($data['channel'], $identifier, $data['country_code'] ?? null);
        $user = $outcome['user'];

        if (! $user->isActive()) {
            return ApiResponse::error('FORBIDDEN', 'This account is not currently active', ['status' => $user->effectiveStatus()], 403);
        }

        $token = $this->auth->issueToken($user, $data['device']);

        return ApiResponse::success([
            ...$token,
            'is_new_user'           => $outcome['is_new_user'],
            'requires_profile_setup' => $this->requiresProfileSetup($user),
            'user'                  => $this->userPayload($user),
        ], 'Signed in');
    }

    /**
     * POST /auth/login — email/password or phone/password. Never registers a new account:
     * a password only works once one already exists, so signing up still goes through OTP
     * (or social) first.
     */
    public function loginWithPassword(Request $request): JsonResponse
    {
        $data = $this->validateIdentifier($request, [
            'password' => ['required', 'string'],
            'device'   => ['required', 'array'],
            ...$this->deviceRules(),
        ]);

        $identifier = $this->resolveIdentifier($data);

        try {
            $user = $this->auth->loginWithPassword($data['channel'], $identifier, $data['password']);
        } catch (\RuntimeException $e) {
            return $e->getMessage() === 'inactive'
                ? ApiResponse::error('FORBIDDEN', 'This account is not currently active', null, 403)
                : ApiResponse::error('UNAUTHENTICATED', 'Those credentials do not match our records', null, 401);
        }

        $token = $this->auth->issueToken($user, $data['device']);

        return ApiResponse::success([
            ...$token,
            'is_new_user'            => false,
            'requires_profile_setup' => $this->requiresProfileSetup($user),
            'user'                   => $this->userPayload($user),
        ], 'Signed in');
    }

    /**
     * POST /auth/social — Google or Facebook. `token` is the id token (Google) or access
     * token (Facebook) the app already obtained from the native SDK.
     */
    public function socialLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(['google', 'facebook'])],
            'token'    => ['required', 'string'],
            'device'   => ['required', 'array'],
            ...$this->deviceRules(),
        ]);

        try {
            $verified = $data['provider'] === 'google'
                ? $this->socialVerifier->verifyGoogle($data['token'])
                : $this->socialVerifier->verifyFacebook($data['token']);
        } catch (\RuntimeException $e) {
            return ApiResponse::error('UNAUTHENTICATED', $e->getMessage(), null, 401);
        }

        $outcome = $this->auth->loginOrRegisterBySocial(
            $data['provider'],
            $verified['provider_user_id'],
            $verified['email'] ?? null,
            $verified['name'] ?? null,
        );
        $user = $outcome['user'];

        if (! $user->isActive()) {
            return ApiResponse::error('FORBIDDEN', 'This account is not currently active', ['status' => $user->effectiveStatus()], 403);
        }

        $token = $this->auth->issueToken($user, $data['device']);

        return ApiResponse::success([
            ...$token,
            'is_new_user'            => $outcome['is_new_user'],
            'requires_profile_setup' => $this->requiresProfileSetup($user),
            'user'                   => $this->userPayload($user),
        ], 'Signed in');
    }

    /**
     * POST /auth/password/forgot — sends a reset OTP. Always the same response whether or
     * not the account exists (see sendOtp for the same enumeration-safety reasoning).
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $this->validateIdentifier($request);
        $identifier = $this->resolveIdentifier($data);

        if ($this->auth->findByIdentifier($data['channel'], $identifier) !== null) {
            $this->otp->send($data['channel'], $identifier, OtpVerification::PURPOSE_RESET_PASSWORD);
        }

        return ApiResponse::success(null, "If that account exists, we've sent a verification code");
    }

    /**
     * POST /auth/password/reset — verifies the reset OTP and sets a new password in one
     * step. Also covers setting a password for the first time on an OTP-only account.
     * Every other device's token is revoked, and the caller is signed in on this one.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $this->validateIdentifier($request, [
            'otp'      => ['required', 'string', 'regex:/^\d{6}$/'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'device'   => ['sometimes', 'array'],
            ...$this->deviceRules(),
        ]);

        $identifier = $this->resolveIdentifier($data);

        $result = $this->otp->verify($data['channel'], $identifier, OtpVerification::PURPOSE_RESET_PASSWORD, $data['otp']);

        if (! $result['ok']) {
            return $this->otpFailure($result);
        }

        $user = $this->auth->findByIdentifier($data['channel'], $identifier);

        if ($user === null) {
            return ApiResponse::error('NOT_FOUND', 'No account matches that identifier', null, 404);
        }

        $user->update(['password' => $data['password']]);
        $user->tokens()->delete();

        $token = $this->auth->issueToken($user, $data['device'] ?? null);

        return ApiResponse::success([
            ...$token,
            'user' => $this->userPayload($user),
        ], 'Password reset');
    }

    /**
     * GET /auth/me.
     */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    /**
     * POST /auth/logout — revokes this device's token only.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Signed out');
    }

    /**
     * POST /auth/profile/setup — the profile-setup screen shown whenever
     * `requires_profile_setup` is true: gender, name, DOB (18+), country, and an optional
     * invite code (another user's `guftagu_id`).
     */
    public function setupProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'display_name'  => ['required', 'string', 'max:50'],
            'gender'        => ['required', 'string', Rule::in(['male', 'female', 'undisclosed'])],
            'date_of_birth' => ['required', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString()],
            'country'       => ['sometimes', 'nullable', 'string', 'max:80'],
            'invite_code'   => ['sometimes', 'nullable', 'string', 'max:20'],
        ], [
            'date_of_birth.before_or_equal' => 'You must be at least 18 years old to register.',
        ]);

        if (! empty($data['invite_code']) && $user->referred_by_user_id === null) {
            $referrer = User::query()->where('guftagu_id', $data['invite_code'])->first();

            if ($referrer === null || $referrer->id === $user->id) {
                throw ValidationException::withMessages(['invite_code' => ['That invite code is not valid.']]);
            }

            $user->update(['referred_by_user_id' => $referrer->id]);
        }

        $user->profile()->updateOrCreate([], [
            'display_name'        => $data['display_name'],
            'gender'               => $data['gender'],
            'date_of_birth'        => $data['date_of_birth'],
            'country'              => $data['country'] ?? null,
            'is_profile_complete'  => true,
        ]);

        return ApiResponse::success([
            'user' => $this->userPayload($user->fresh()),
        ], 'Profile saved');
    }

    // ------------------------------------------------------------------ internals

    /**
     * Shared validation + normalization for every endpoint keyed on an email or phone.
     * `$extra` rules are merged in so callers only state what makes them different.
     */
    protected function validateIdentifier(Request $request, array $extra = []): array
    {
        return $request->validate([
            'channel'      => ['required', 'string', Rule::in([OtpVerification::CHANNEL_EMAIL, OtpVerification::CHANNEL_PHONE])],
            'email'        => ['required_if:channel,email', 'string', 'email:filter', 'max:191'],
            'phone'        => ['required_if:channel,phone', 'string', 'regex:/^[0-9]{6,15}$/'],
            'country_code' => ['sometimes', 'nullable', 'string', 'max:5'],
            ...$extra,
        ]);
    }

    protected function deviceRules(): array
    {
        return [
            'device.device_id'   => ['required_with:device', 'string', 'max:191'],
            'device.platform'    => ['required_with:device', 'string', Rule::in(['android', 'ios'])],
            'device.fcm_token'   => ['sometimes', 'nullable', 'string', 'max:500'],
            'device.app_version' => ['sometimes', 'nullable', 'string', 'max:20'],
            'device.os_version'  => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    protected function resolveIdentifier(array $data): string
    {
        if ($data['channel'] === OtpVerification::CHANNEL_PHONE) {
            $country = $data['country_code'] ?: '+91';
            $digits = preg_replace('/\D/', '', $data['phone']);

            return $country.$digits;
        }

        return strtolower(trim($data['email']));
    }

    protected function requiresProfileSetup(User $user): bool
    {
        return ! (bool) ($user->profile?->is_profile_complete ?? false);
    }

    protected function otpFailure(array $result): JsonResponse
    {
        return match ($result['error']) {
            'expired'           => ApiResponse::error('BAD_REQUEST', 'That code has expired — request a new one', null, 400),
            'too_many_attempts' => ApiResponse::error('RATE_LIMITED', 'Too many incorrect codes — request a new one', null, 429),
            default             => ApiResponse::error(
                'UNAUTHENTICATED',
                'That code is not correct',
                ['attempts_left' => $result['attempts_left'] ?? null],
                401,
            ),
        };
    }

    protected function userPayload(User $user): array
    {
        $profile = $user->profile;

        return [
            'uuid'                => $user->uuid,
            'guftagu_id'          => $user->guftagu_id,
            'display_name'        => $profile?->display_name,
            'avatar_url'          => $profile?->avatar_url,
            'gender'              => $profile?->gender,
            'date_of_birth'       => $profile?->date_of_birth?->toDateString(),
            'country'             => $profile?->country,
            'agora_uid'           => $user->agora_uid,
            'status'              => $user->status,
            'is_profile_complete' => (bool) ($profile?->is_profile_complete ?? false),
        ];
    }
}
