<?php

namespace App\Domain\Onboarding\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Epic D.1a — verifies the id/access token the app already obtained from the native
 * Google/Facebook SDK. The app never sends a password for these; trusting the token
 * without checking it against the provider would let anyone claim any account.
 *
 * @throws RuntimeException with a message safe to surface as the API error when the token
 *                           is missing, expired, or was not issued for this app.
 */
class SocialTokenVerifier
{
    /**
     * @return array{provider_user_id: string, email: ?string, name: ?string}
     */
    public function verifyGoogle(string $idToken): array
    {
        try {
            $response = Http::timeout(5)->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $idToken,
            ]);
        } catch (\Throwable $e) {
            Log::error('google_auth.lookup_failed', ['message' => $e->getMessage()]);

            throw new RuntimeException('Could not verify the Google sign-in token');
        }

        if (! $response->successful()) {
            throw new RuntimeException('That Google sign-in token is invalid or has expired');
        }

        $clientId = config('services.google.client_id');
        $aud = $response->json('aud');

        // aud must match this app's own client id — otherwise a token minted for a
        // different Google app would sign in here too.
        if (! is_string($clientId) || $clientId === '' || $aud !== $clientId) {
            throw new RuntimeException('That Google sign-in token was not issued for this app');
        }

        $sub = $response->json('sub');

        if (! is_string($sub) || $sub === '') {
            throw new RuntimeException('That Google sign-in token is invalid or has expired');
        }

        return [
            'provider_user_id' => $sub,
            'email'            => $response->json('email'),
            'name'             => $response->json('name'),
        ];
    }

    /**
     * @return array{provider_user_id: string, email: ?string, name: ?string}
     */
    public function verifyFacebook(string $accessToken): array
    {
        $appId = config('services.facebook.app_id');
        $appSecret = config('services.facebook.app_secret');

        if (! is_string($appId) || $appId === '' || ! is_string($appSecret) || $appSecret === '') {
            throw new RuntimeException('Facebook sign-in is not configured yet');
        }

        try {
            $debug = Http::timeout(5)->get('https://graph.facebook.com/debug_token', [
                'input_token'  => $accessToken,
                'access_token' => "{$appId}|{$appSecret}",
            ]);

            $me = Http::timeout(5)->get('https://graph.facebook.com/me', [
                'fields'       => 'id,name,email',
                'access_token' => $accessToken,
            ]);
        } catch (\Throwable $e) {
            Log::error('facebook_auth.lookup_failed', ['message' => $e->getMessage()]);

            throw new RuntimeException('Could not verify the Facebook sign-in token');
        }

        if (! $debug->successful() || $debug->json('data.is_valid') !== true || (string) $debug->json('data.app_id') !== $appId) {
            throw new RuntimeException('That Facebook sign-in token is invalid or has expired');
        }

        $userId = $me->json('id');

        if (! $me->successful() || ! is_string($userId) || $userId === '') {
            throw new RuntimeException('That Facebook sign-in token is invalid or has expired');
        }

        return [
            'provider_user_id' => $userId,
            'email'            => $me->json('email'),
            'name'             => $me->json('name'),
        ];
    }
}
