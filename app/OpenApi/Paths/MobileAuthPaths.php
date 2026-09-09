<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operations for epic D.1a — App\Http\Controllers\Api\AuthController.
 *
 * Kept separate from AuthPaths.php (admin auth) — same reasoning as that file: **if you
 * change a route, request or response in AuthController, update this file in the same
 * commit.** Swagger UI otherwise only ever documented the admin panel; these are the first
 * mobile operations added to it, at the requester's ask, so they can "Try it out" against a
 * running backend the same way the admin routes already can.
 */
#[OA\Post(
    path: '/auth/otp/send',
    summary: 'Send a sign-in/registration or password-reset code (D.1a)',
    description: <<<'MD'
Works for an email or phone that has never signed up before — verifying it via
`/auth/otp/verify` with `purpose: auth` is what registers the account.

For `purpose: reset_password` the response is identical whether or not the account
exists; only whether a code actually goes out differs, so this cannot be used to probe
which phones/emails are registered.

Throttled 3/hour per identifier and 10/day per IP. In `APP_ENV=local`, the code is a
fixed 6-digit value (`USER_OTP_STATIC_OTP` in .env) rather than a mailed/texted one.
MD,
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['channel', 'purpose'],
            properties: [
                new OA\Property(property: 'channel', type: 'string', enum: ['email', 'phone']),
                new OA\Property(property: 'email', type: 'string', format: 'email', description: 'Required when channel is email'),
                new OA\Property(property: 'phone', type: 'string', description: 'Digits only, no country code. Required when channel is phone', example: '9876543210'),
                new OA\Property(property: 'country_code', type: 'string', example: '+91', default: '+91'),
                new OA\Property(property: 'purpose', type: 'string', enum: ['auth', 'reset_password']),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Code sent (or silently skipped for reset_password on an unknown account)', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 429, description: '`RATE_LIMITED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/auth/otp/verify',
    summary: 'Verify a sign-in/registration code (D.1a)',
    description: 'Logs into the existing account when the phone/email is already registered (`is_new_user: false`), or creates one (`is_new_user: true`) when it is not. `requires_profile_setup` tells the app whether to show the profile-setup screen next.',
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['channel', 'otp', 'device'],
            properties: [
                new OA\Property(property: 'channel', type: 'string', enum: ['email', 'phone']),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'phone', type: 'string', example: '9876543210'),
                new OA\Property(property: 'country_code', type: 'string', example: '+91'),
                new OA\Property(property: 'otp', type: 'string', pattern: '^\d{6}$', example: '123456'),
                new OA\Property(property: 'device', type: 'object', required: ['device_id', 'platform'], properties: [
                    new OA\Property(property: 'device_id', type: 'string'),
                    new OA\Property(property: 'platform', type: 'string', enum: ['android', 'ios']),
                    new OA\Property(property: 'fcm_token', type: 'string', nullable: true),
                    new OA\Property(property: 'app_version', type: 'string', nullable: true),
                    new OA\Property(property: 'os_version', type: 'string', nullable: true),
                ]),
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Signed in',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'is_new_user', type: 'boolean'),
                    new OA\Property(property: 'requires_profile_setup', type: 'boolean'),
                    new OA\Property(property: 'user', ref: '#/components/schemas/MobileUser'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — the code expired', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED` — wrong code; `details.attempts_left` counts down', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 403, description: '`FORBIDDEN` — the account is suspended/banned', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 429, description: '`RATE_LIMITED` — too many incorrect codes', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/auth/login',
    summary: 'Log in with email/password or phone/password (D.1a)',
    description: 'Never registers a new account — a password only works once one already exists via OTP or social sign-in first. One error for "no such account", "no password set" (OTP-only account) and "wrong password", same enumeration-safety reasoning as the admin login.',
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['channel', 'password', 'device'],
            properties: [
                new OA\Property(property: 'channel', type: 'string', enum: ['email', 'phone']),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'phone', type: 'string', example: '9876543210'),
                new OA\Property(property: 'country_code', type: 'string', example: '+91'),
                new OA\Property(property: 'password', type: 'string', format: 'password'),
                new OA\Property(property: 'device', ref: '#/components/schemas/MobileDevice'),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Signed in', content: new OA\JsonContent(ref: '#/components/schemas/MobileAuthResponse')),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 403, description: '`FORBIDDEN` — suspended/banned', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 429, description: '`RATE_LIMITED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/auth/social',
    summary: 'Sign in/register with Google or Facebook (D.1a)',
    description: '`token` is the id token (Google) or access token (Facebook) the app already obtained from the native SDK — it is verified against the provider before anything is trusted. If the verified email matches an existing account, the social account is linked to it rather than creating a duplicate.',
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['provider', 'token', 'device'],
            properties: [
                new OA\Property(property: 'provider', type: 'string', enum: ['google', 'facebook']),
                new OA\Property(property: 'token', type: 'string'),
                new OA\Property(property: 'device', ref: '#/components/schemas/MobileDevice'),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Signed in', content: new OA\JsonContent(ref: '#/components/schemas/MobileAuthResponse')),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED` — the token is invalid, expired, or was not issued for this app', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 403, description: '`FORBIDDEN` — suspended/banned', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/auth/password/forgot',
    summary: 'Request a password-reset code (D.1a)',
    description: 'Same body as `/auth/otp/send` with `purpose` fixed to `reset_password`. Always returns the same message — enumeration-safe.',
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['channel'],
            properties: [
                new OA\Property(property: 'channel', type: 'string', enum: ['email', 'phone']),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'phone', type: 'string', example: '9876543210'),
                new OA\Property(property: 'country_code', type: 'string', example: '+91'),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: "If that account exists, we've sent a verification code", content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
    ]
)]
#[OA\Post(
    path: '/auth/password/reset',
    summary: 'Verify the reset code and set a new password (D.1a)',
    description: 'Also how a password is set for the first time on an OTP-only account. Every other device token is revoked, and the caller is signed in on this one.',
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['channel', 'otp', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'channel', type: 'string', enum: ['email', 'phone']),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'phone', type: 'string', example: '9876543210'),
                new OA\Property(property: 'country_code', type: 'string', example: '+91'),
                new OA\Property(property: 'otp', type: 'string', pattern: '^\d{6}$'),
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
                new OA\Property(property: 'device', type: 'object', nullable: true, ref: '#/components/schemas/MobileDevice'),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Password reset', content: new OA\JsonContent(ref: '#/components/schemas/MobileAuthResponse')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — the code expired', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED` — wrong code', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/auth/me',
    summary: 'Current mobile user',
    security: [['bearerAuth' => []]],
    tags: ['Auth'],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'user', ref: '#/components/schemas/MobileUser'),
            ]),
            new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
        ])),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/auth/logout',
    summary: "Revoke this device's token",
    security: [['bearerAuth' => []]],
    tags: ['Auth'],
    responses: [
        new OA\Response(response: 200, description: 'Signed out', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/auth/profile/setup',
    summary: 'Complete the profile-setup screen (D.1b)',
    description: 'Shown whenever `requires_profile_setup` is true. `invite_code` is another user\'s `guftagu_id`; once a referrer is recorded it cannot be changed by calling this again.',
    security: [['bearerAuth' => []]],
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['display_name', 'gender', 'date_of_birth'],
            properties: [
                new OA\Property(property: 'display_name', type: 'string', maxLength: 50),
                new OA\Property(property: 'gender', type: 'string', enum: ['male', 'female', 'undisclosed']),
                new OA\Property(property: 'date_of_birth', type: 'string', format: 'date', description: 'Must be 18+ years ago'),
                new OA\Property(property: 'country', type: 'string', nullable: true),
                new OA\Property(property: 'invite_code', type: 'string', nullable: true, example: 'GF8420156'),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Profile saved', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'user', ref: '#/components/schemas/MobileUser'),
            ]),
            new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
        ])),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — under 18, or an invalid invite code', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Schema(
    schema: 'MobileDevice',
    required: ['device_id', 'platform'],
    properties: [
        new OA\Property(property: 'device_id', type: 'string'),
        new OA\Property(property: 'platform', type: 'string', enum: ['android', 'ios']),
        new OA\Property(property: 'fcm_token', type: 'string', nullable: true),
        new OA\Property(property: 'app_version', type: 'string', nullable: true),
        new OA\Property(property: 'os_version', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'MobileUser',
    properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'guftagu_id', type: 'string', example: 'GF8420156'),
        new OA\Property(property: 'display_name', type: 'string', nullable: true),
        new OA\Property(property: 'avatar_url', type: 'string', nullable: true),
        new OA\Property(property: 'gender', type: 'string', nullable: true),
        new OA\Property(property: 'date_of_birth', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'country', type: 'string', nullable: true),
        new OA\Property(property: 'agora_uid', type: 'integer'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'is_profile_complete', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'MobileAuthResponse',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'data', type: 'object', properties: [
            new OA\Property(property: 'token', type: 'string'),
            new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'is_new_user', type: 'boolean'),
            new OA\Property(property: 'requires_profile_setup', type: 'boolean'),
            new OA\Property(property: 'user', ref: '#/components/schemas/MobileUser'),
        ]),
        new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
    ]
)]
class MobileAuthPaths
{
}
