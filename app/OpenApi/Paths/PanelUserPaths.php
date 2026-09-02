<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operations for AdminUserController (GFT-127) and AdminPermissionController
 * (epic A.11 — the delegation endpoints).
 *
 * Keep in sync with those controllers by hand — see the note in AuthPaths.
 */
#[OA\Get(
    path: '/admin/admins',
    summary: 'List panel users',
    description: 'Offset pagination per docs/03 §2.3. `sort` accepts `-field` for descending and is restricted to an allowlist — an unlisted column silently falls back to `-created_at` rather than reaching an arbitrary column.',
    security: [['bearerAuth' => []]],
    tags: ['Panel Users'],
    parameters: [
        new OA\Parameter(name: 'q', description: 'Matches name or email', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'suspended'])),
        new OA\Parameter(name: 'role', description: 'Role key', in: 'query', schema: new OA\Schema(type: 'string', enum: ['super_admin', 'admin', 'manager', 'moderator'])),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1), example: 1),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1), example: 20),
        new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['id', 'name', 'email', 'status', 'created_at', 'last_login_at', '-id', '-name', '-email', '-status', '-created_at', '-last_login_at']), example: '-created_at'),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AdminProfile')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `access.admin_manage`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/admins',
    summary: 'Create a panel user',
    description: 'The delegation ladder applies: a Super Admin may create any role; an Admin may create only `manager` and `moderator`. Only a Super Admin can mint another Super Admin.',
    security: [['bearerAuth' => []]],
    tags: ['Panel Users'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(required: ['name', 'email', 'password', 'role'], properties: [
            new OA\Property(property: 'name', type: 'string', example: 'Night Mod'),
            new OA\Property(property: 'email', type: 'string', format: 'email', example: 'mod@guftagu.local'),
            new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 12, example: 'ModPass123456'),
            new OA\Property(property: 'role', type: 'string', enum: ['admin', 'manager', 'moderator'], example: 'moderator'),
            new OA\Property(property: 'phone', type: 'string', nullable: true),
        ])
    ),
    responses: [
        new OA\Response(
            response: 201,
            description: 'Panel user created',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/AdminProfile'),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`DELEGATION_TARGET_DENIED` — not allowed to create that role', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — duplicate email, or password under 12 characters', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/admins/{admin}',
    summary: 'One panel user',
    security: [['bearerAuth' => []]],
    tags: ['Panel Users'],
    parameters: [
        new OA\Parameter(name: 'admin', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 2),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/AdminProfile'),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Patch(
    path: '/admin/admins/{admin}',
    summary: 'Update a panel user',
    description: 'Changing `role` flushes that admin’s cached permission set, so the new baseline is enforced on their next request. `session_timeout_minutes` overrides the platform default for this account only; `null` returns them to it.',
    security: [['bearerAuth' => []]],
    tags: ['Panel Users'],
    parameters: [
        new OA\Parameter(name: 'admin', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 2),
    ],
    requestBody: new OA\RequestBody(
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'phone', type: 'string', nullable: true),
            new OA\Property(property: 'role', type: 'string', enum: ['admin', 'manager', 'moderator']),
            new OA\Property(property: 'mfa_enabled', type: 'boolean', description: 'Per-account opt-in; can only add to the role policy, never disable it'),
            new OA\Property(property: 'session_timeout_minutes', type: 'integer', maximum: 1440, minimum: 0, nullable: true),
        ])
    ),
    responses: [
        new OA\Response(response: 200, description: 'Panel user updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`DELEGATION_TARGET_DENIED` — not allowed to manage that account, or assign that role', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/admins/{admin}/status',
    summary: 'Activate or suspend a panel user',
    description: 'Suspension **ends live sessions immediately** — every token for the account is deleted and their cached permission set is flushed, so it does not merely block the next login. You cannot change your own status.',
    security: [['bearerAuth' => []]],
    tags: ['Panel Users'],
    parameters: [
        new OA\Parameter(name: 'admin', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 3),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(required: ['status'], properties: [
            new OA\Property(property: 'status', type: 'string', enum: ['active', 'suspended'], example: 'suspended'),
            new OA\Property(property: 'reason', type: 'string', nullable: true, example: 'Left the team'),
        ])
    ),
    responses: [
        new OA\Response(response: 200, description: 'Status updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — you cannot change your own status', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 403, description: '`DELEGATION_TARGET_DENIED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]

// -------------------------------------------------------------------- delegation

#[OA\Get(
    path: '/admin/admins/{admin}/permissions',
    summary: 'Effective permissions with origin (GFT-126)',
    description: 'Shows where each permission comes from — role baseline, direct grant, or an explicit deny. Denied rows are included precisely so an operator can see **why** something is missing.',
    security: [['bearerAuth' => []]],
    tags: ['Delegation'],
    parameters: [
        new OA\Parameter(name: 'admin', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 3),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'admin', type: 'object', properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'role', type: 'string'),
                    ]),
                    new OA\Property(property: 'effective_keys', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'detail', type: 'array', items: new OA\Items(ref: '#/components/schemas/EffectivePermissionRow')),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `access.permission_grant`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/admins/{admin}/permissions',
    summary: 'Grant permissions — the escalation guard (GFT-117)',
    description: <<<'MD'
The single server-side chokepoint for delegation. Guards run in this order, so the caller
learns the most fundamental reason for refusal:

1. **`SELF_GRANT_DENIED`** — never to yourself. A Super Admin is not exempt.
2. **`DELEGATION_TARGET_DENIED`** — Super Admin → anyone; Admin → `manager`/`moderator`; everyone else → nobody.
3. **`PERMISSION_ESCALATION_DENIED`** — you cannot give away what you do not hold. Refused **wholesale**: a call mixing held and unheld keys grants nothing.
4. **`MFA_REQUIRED`** — a `high` risk_level key needs fresh MFA (GFT-122).

On refusal nothing is persisted and the attempt is written to `audit_logs`. Hiding the
option in the UI does not satisfy this — the guard holds on a direct API call.

A grant over an existing deny flips it back to `allow`, and the log records that transition.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Delegation'],
    parameters: [
        new OA\Parameter(name: 'admin', description: 'The target panel user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 3),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(required: ['permissions'], properties: [
            new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), example: ['moderation.mute_user', 'moderation.kick_user']),
            new OA\Property(property: 'scope', ref: '#/components/schemas/GrantScope', nullable: true),
            new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', description: 'Must be in the future. An expired grant is excluded from the effective set immediately, without waiting for the expiry job', nullable: true),
            new OA\Property(property: 'reason', type: 'string', nullable: true, example: 'Night-shift moderator, music rooms only'),
        ])
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Granted',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: '2 permissions granted'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'granted', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'effective_count', type: 'integer', example: 7),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`SELF_GRANT_DENIED` · `DELEGATION_TARGET_DENIED` · `PERMISSION_ESCALATION_DENIED` (with `details.ungranted`) · `MFA_REQUIRED` (with `details.high_risk`) · `PERMISSION_DENIED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — unknown permission key, or `expires_at` not in the future', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Delete(
    path: '/admin/admins/{admin}/permissions',
    summary: 'Revoke a direct grant (GFT-118)',
    description: 'Removes the row in `admin_user_permission`. It does **not** suppress a permission held through the role baseline — that is what an explicit deny is for. When a revoked key is still held via the role, `still_held_via_role` says so rather than reporting a misleading success.',
    security: [['bearerAuth' => []]],
    tags: ['Delegation'],
    parameters: [
        new OA\Parameter(name: 'admin', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 3),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(required: ['permissions'], properties: [
            new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), example: ['moderation.mute_user']),
            new OA\Property(property: 'reason', type: 'string', nullable: true, example: 'Shift ended'),
        ])
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Revoked',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'revoked', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'still_held_via_role', type: 'array', items: new OA\Items(type: 'string'), example: []),
                    new OA\Property(property: 'effective_count', type: 'integer', example: 5),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`SELF_GRANT_DENIED` · `DELEGATION_TARGET_DENIED` · `PERMISSION_DENIED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/admins/{admin}/permissions/deny',
    summary: 'Explicitly deny a permission over a role grant (GFT-118)',
    description: 'How you take one permission away from someone whose role baseline includes it, without inventing a new role. The resolver subtracts denies last, so a deny always wins. The escalation guard and the high-risk MFA rule apply here too.',
    security: [['bearerAuth' => []]],
    tags: ['Delegation'],
    parameters: [
        new OA\Parameter(name: 'admin', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 2),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(required: ['permissions'], properties: [
            new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), example: ['payouts.approve']),
            new OA\Property(property: 'reason', type: 'string', nullable: true, example: 'Finance handles payouts now'),
        ])
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Denied',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'denied', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'effective_count', type: 'integer', example: 75),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`SELF_GRANT_DENIED` · `DELEGATION_TARGET_DENIED` · `PERMISSION_ESCALATION_DENIED` · `MFA_REQUIRED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/admins/{admin}/permission-log',
    summary: 'Grant and revoke history for one admin',
    description: 'Reads `permission_grants_log`, which is append-only — never updated, never deleted. Requires `access.audit_view`.',
    security: [['bearerAuth' => []]],
    tags: ['Delegation'],
    parameters: [
        new OA\Parameter(name: 'admin', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 3),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'action', type: 'string', enum: ['grant', 'revoke', 'scope_change', 'deny']),
                    new OA\Property(property: 'permission', type: 'string', example: 'moderation.mute_user'),
                    new OA\Property(property: 'effect_before', type: 'string', nullable: true, example: null),
                    new OA\Property(property: 'effect_after', type: 'string', nullable: true, example: 'allow'),
                    new OA\Property(property: 'scope', ref: '#/components/schemas/GrantScope', nullable: true),
                    new OA\Property(property: 'reason', type: 'string', nullable: true),
                    new OA\Property(property: 'actor', type: 'object', nullable: true, properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'name', type: 'string'),
                    ]),
                    new OA\Property(property: 'ip', type: 'string', nullable: true),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                ])),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `access.audit_view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
class PanelUserPaths
{
}
