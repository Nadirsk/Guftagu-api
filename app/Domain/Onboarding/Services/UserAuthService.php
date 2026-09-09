<?php

namespace App\Domain\Onboarding\Services;

use App\Models\Device;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;

/**
 * Epic D.1a — account resolution, provisioning and token issuance for every sign-in path
 * (OTP, password, Google, Facebook). The one invariant every path shares: a `phone`/`email`
 * that already belongs to an account signs into it — it never creates a second one.
 */
class UserAuthService
{
    /**
     * @return array{user: User, is_new_user: bool}
     */
    public function loginOrRegisterByOtp(string $channel, string $identifier, ?string $countryCode): array
    {
        $column = $channel === 'phone' ? 'phone_hash' : 'email_hash';
        $user = User::query()->where($column, User::hash($identifier))->first();

        if ($user !== null) {
            return ['user' => $user, 'is_new_user' => false];
        }

        $attributes = $channel === 'phone'
            ? ['phone' => $identifier, 'country_code' => $countryCode ?: '+91']
            : ['email' => $identifier];

        $user = $this->createUser($attributes, displayName: null);

        return ['user' => $user, 'is_new_user' => true];
    }

    /**
     * @return array{user: User, is_new_user: bool}
     */
    public function loginOrRegisterBySocial(string $provider, string $providerUserId, ?string $email, ?string $name): array
    {
        $column = $provider === 'google' ? 'google_id' : 'facebook_id';

        $user = User::query()->where($column, $providerUserId)->first();

        if ($user !== null) {
            return ['user' => $user, 'is_new_user' => false];
        }

        // D.1a — "Given Google sign-in with an email matching an existing account, then
        // the social account is linked to it rather than creating a second account."
        if ($email !== null) {
            $existing = User::query()->where('email_hash', User::hash($email))->first();

            if ($existing !== null) {
                $existing->update([$column => $providerUserId]);

                return ['user' => $existing, 'is_new_user' => false];
            }
        }

        $attributes = [$column => $providerUserId];

        if ($email !== null) {
            $attributes['email'] = $email;
        }

        $user = $this->createUser($attributes, $name);

        return ['user' => $user, 'is_new_user' => true];
    }

    /**
     * @throws \RuntimeException when the credentials do not match an active account
     */
    public function loginWithPassword(string $channel, string $identifier, string $password): User
    {
        $column = $channel === 'phone' ? 'phone_hash' : 'email_hash';
        $user = User::query()->where($column, User::hash($identifier))->first();

        // One message for "no such account", "no password set" (OTP-only account) and
        // "wrong password" — enumeration is not a feature, same as AdminAuthController.
        if ($user === null || $user->password === null || ! Hash::check($password, $user->password)) {
            throw new \RuntimeException('invalid_credentials');
        }

        if (! $user->isActive()) {
            throw new \RuntimeException('inactive');
        }

        return $user;
    }

    public function findByIdentifier(string $channel, string $identifier): ?User
    {
        $column = $channel === 'phone' ? 'phone_hash' : 'email_hash';

        return User::query()->where($column, User::hash($identifier))->first();
    }

    /**
     * @return array{token: string, expires_at: string}
     */
    public function issueToken(User $user, ?array $device): array
    {
        $expiration = (int) config('sanctum.expiration', 1440);

        $deviceName = is_array($device) && ! empty($device['device_id'])
            ? (string) $device['device_id']
            : 'mobile-app';

        $token = $user->createToken($deviceName, ['*'], now()->addMinutes($expiration));

        if (is_array($device) && ! empty($device['device_id'])) {
            $this->registerDevice($user, $device);
        }

        return [
            'token'      => $token->plainTextToken,
            'expires_at' => now()->addMinutes($expiration)->toIso8601ZuluString(),
        ];
    }

    public function registerDevice(User $user, array $device): void
    {
        Device::updateOrCreate(
            ['device_id' => $device['device_id']],
            [
                'user_id'      => $user->id,
                'platform'     => $device['platform'] ?? 'android',
                'fcm_token'    => $device['fcm_token'] ?? null,
                'app_version'  => $device['app_version'] ?? null,
                'os_version'   => $device['os_version'] ?? null,
                'last_seen_at' => now(),
                'is_active'    => true,
            ],
        );
    }

    /**
     * Creates the account plus its (incomplete) profile row. `guftagu_id`/`agora_uid` are
     * generated here rather than trusted from a caller, and collisions are retried rather
     * than assumed away — GFT-187, "collision-safe".
     */
    protected function createUser(array $attributes, ?string $displayName): User
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $user = User::create([
                    ...$attributes,
                    'guftagu_id'      => $this->generateGuftaguId(),
                    'agora_uid'       => $this->generateAgoraUid(),
                    'registered_ip'   => request()?->ip(),
                ]);

                UserProfile::create([
                    'user_id'             => $user->id,
                    'display_name'        => $displayName !== null && trim($displayName) !== ''
                        ? trim($displayName)
                        : $user->guftagu_id,
                    'is_profile_complete' => false,
                ]);

                return $user->fresh();
            } catch (QueryException $e) {
                // Unique collision on guftagu_id/agora_uid — vanishingly rare, retried
                // rather than surfaced to the caller.
                if ($attempt === 4) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not provision a new account');
    }

    protected function generateGuftaguId(): string
    {
        do {
            $candidate = 'GF'.str_pad((string) random_int(1_000_000, 9_999_999), 7, '0', STR_PAD_LEFT);
        } while (User::query()->where('guftagu_id', $candidate)->exists());

        return $candidate;
    }

    protected function generateAgoraUid(): int
    {
        do {
            $candidate = random_int(100_000, 999_999_999);
        } while (User::query()->where('agora_uid', $candidate)->exists());

        return $candidate;
    }
}
