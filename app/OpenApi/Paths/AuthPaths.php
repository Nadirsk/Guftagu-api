<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operations for epic A.1 — App\Http\Controllers\Admin\AdminAuthController.
 *
 * Operations are stacked on this class rather than on the controller methods so 27
 * endpoints' worth of attributes stay out of the business logic. Nothing enforces the
 * link: **if you change a route, request or response in AdminAuthController, update this
 * file in the same commit.**
 *
 * Paths are relative to the server URL, which already ends in `/api/v1`.
 */
#[OA\Post(
    path: '/admin/auth/login',
    summary: 'Log in with email and password (A.1a)',
    description: <<<'MD'
Returns an **MFA challenge** when 2FA applies to the caller's role, or a token when it
does not — never both. No access token exists until the OTP is verified.

Throttled to 5/min per IP *and* per email. Five consecutive password failures lock the
account for 15 minutes; while locked, even the correct password is refused.

A verified password clears the failure streak, including when it only reaches the MFA
stage — the lockout guards the password, and the OTP stage has its own limits.
MD,
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'super@guftagu.local'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'Guftagu@2026'),
                new OA\Property(property: 'remember_device', type: 'boolean', description: 'Accepted and stored, but device remembering is not wired up yet', example: false),
                new OA\Property(property: 'device_name', type: 'string', description: 'Labels the token row in personal_access_tokens', example: 'chrome-macbook'),
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Either a challenge (MFA required) or a token',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Enter the code we emailed you'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'mfa_required', type: 'boolean', example: true),
                    new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid', description: 'Present only when mfa_required is true'),
                    new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'sent_to', type: 'string', description: 'Masked — the panel shows this, never the full address', example: 'su•••@guftagu.local'),
                    new OA\Property(property: 'token', type: 'string', description: 'Present only when mfa_required is false'),
                    new OA\Property(property: 'idle_timeout_minutes', type: 'integer', example: 60),
                    new OA\Property(property: 'admin', ref: '#/components/schemas/AdminProfile'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED` — wrong password, or no such account. Deliberately identical for both, so this is not an enumeration oracle', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 403, description: '`FORBIDDEN` — the account is suspended', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 423, description: '`ACCOUNT_LOCKED` — five failures. `Retry-After` header and `details.locked_until` are returned', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 429, description: '`RATE_LIMITED` — more than 5 attempts in a minute', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/auth/mfa/verify',
    summary: 'Verify the emailed OTP and receive a token (A.1a)',
    description: <<<'MD'
Consumes the challenge. An OTP is single-use, valid for 10 minutes, and allows 5 attempts
before the challenge is burnt.

**Getting the code locally:** `GET /admin/dev/last-otp`, or

```
grep -oE "^# [0-9]{6}$" storage/logs/laravel.log | tail -1
```
MD,
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['challenge_id', 'otp'],
            properties: [
                new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'otp', type: 'string', pattern: '^\d{6}$', example: '123456'),
                new OA\Property(property: 'device_name', type: 'string', example: 'chrome-macbook'),
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Signed in',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Signed in'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'token', type: 'string', example: '1|sY8ESTgrWmfE4wuefoPsgAmA5Kj3mmuWuZXTlHWM'),
                    new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'idle_timeout_minutes', type: 'integer', example: 60),
                    new OA\Property(property: 'admin', ref: '#/components/schemas/AdminProfile'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — the code was already used, or has expired', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED` — wrong code; `details.attempts_left` counts down', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND` — no such challenge', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 429, description: '`RATE_LIMITED` — too many incorrect codes; start again', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/auth/me',
    summary: 'Current admin, role and effective permissions',
    description: '**The panel renders from this.** `data.permissions` is the resolved set — role baseline ∪ direct allows − direct denies, with expired grants excluded. A Super Admin gets all 79 keys by short-circuit.',
    security: [['bearerAuth' => []]],
    tags: ['Auth'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'OK'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'admin', ref: '#/components/schemas/AdminProfile'),
                    new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), example: ['rooms.view', 'rooms.monitor_live', 'rooms.join_silent', 'moderation.logs_view', 'reports.view']),
                    new OA\Property(property: 'session', type: 'object', properties: [
                        new OA\Property(property: 'idle_timeout_minutes', type: 'integer', example: 60),
                        new OA\Property(property: 'reauth_satisfied', type: 'boolean', description: 'Whether a high-risk grant would go through right now (GFT-122)', example: false),
                    ]),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`, or `TOKEN_EXPIRED` once the idle window has lapsed — the token is deleted at that point', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 403, description: '`FORBIDDEN` — suspended mid-session; all the account’s tokens are deleted', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/auth/logout',
    summary: 'Revoke the current token',
    security: [['bearerAuth' => []]],
    tags: ['Auth'],
    responses: [
        new OA\Response(response: 200, description: 'Signed out', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Patch(
    path: '/admin/auth/profile',
    summary: 'Update your own profile (A.1b)',
    description: '`email` is only accepted from Super Admin and Admin accounts — Manager/Moderator accounts are provisioned by an admin and get `FORBIDDEN` if they send it.',
    security: [['bearerAuth' => []]],
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string', example: 'Nadir Shaikh'),
            new OA\Property(property: 'phone', type: 'string', example: '+919876543210', nullable: true),
            new OA\Property(property: 'avatar_url', type: 'string', format: 'uri', nullable: true),
            new OA\Property(property: 'email', type: 'string', format: 'email', example: 'nadir@example.com', description: 'super_admin/admin only'),
        ])
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Profile updated',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Profile updated'),
                new OA\Property(property: 'data', ref: '#/components/schemas/AdminProfile'),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`FORBIDDEN` — role may not change its own email', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/auth/password',
    summary: 'Change your own password (A.1b)',
    description: 'Requires the current password. On success **every other device token is revoked** — a password change is how you recover from a compromise, so the attacker’s session must not survive it. The token you called with stays valid.',
    security: [['bearerAuth' => []]],
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['current_password', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'current_password', type: 'string', format: 'password'),
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 12, example: 'BrandNewPass99'),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'BrandNewPass99'),
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Password changed',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Password changed'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'other_sessions_revoked', type: 'boolean', example: true),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — missing or wrong `current_password`, shorter than 12, or not confirmed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/translate',
    summary: 'Draft-translate a name field into Hindi',
    description: 'A convenience for the bilingual name fields across the catalogue screens (categories, gifts, levels, VIP tiers) — not the app\'s own i18n system. Best-effort: backed by an unauthenticated third-party translation API with no SLA, so a failure or empty result is reported as `translated: null` rather than an error — the field stays a normal editable input either way.',
    security: [['bearerAuth' => []]],
    tags: ['Auth'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['text'], properties: [
        new OA\Property(property: 'text', type: 'string', maxLength: 200, example: 'Music'),
        new OA\Property(property: 'target', type: 'string', enum: ['hi'], default: 'hi'),
    ])),
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'translated', type: 'string', nullable: true, example: 'संगीत'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
    ]
)]
#[OA\Post(
    path: '/admin/auth/mfa/reauth',
    summary: 'Request an OTP to confirm a high-risk action (GFT-122)',
    description: 'Granting or denying a `high` risk_level permission needs fresh MFA. Call this, then `/admin/auth/mfa/reauth/verify`, then retry the grant within the confirmation window (5 minutes by default).',
    security: [['bearerAuth' => []]],
    tags: ['Auth'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Challenge issued, or re-auth is disabled platform-wide',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'reauth_required', type: 'boolean', example: true),
                    new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'sent_to', type: 'string', example: 'su•••@guftagu.local'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/auth/mfa/reauth/verify',
    summary: 'Confirm the high-risk action OTP (GFT-122)',
    security: [['bearerAuth' => []]],
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['challenge_id', 'otp'],
            properties: [
                new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'otp', type: 'string', pattern: '^\d{6}$', example: '123456'),
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Confirmed',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Confirmed'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'confirmed_for_minutes', type: 'integer', example: 5),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED` — wrong code', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 403, description: '`FORBIDDEN` — that challenge belongs to a different admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
class AuthPaths
{
}
