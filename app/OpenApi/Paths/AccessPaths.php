<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operations for RoleController, PermissionController, and the two security-policy
 * endpoints on AdminAuthController.
 *
 * Keep in sync with those controllers by hand — see the note in AuthPaths.
 */
#[OA\Post(
    path: '/admin/auth/mfa/toggle/{roleKey}',
    summary: 'Enable or disable 2FA for a sub-role (A.1d)',
    description: 'The role policy governs; a per-account `mfa_enabled` opt-in can only add to it. Disabling 2FA for `moderator` therefore stops challenges for moderators who have not individually opted in. Audited with actor, before and after.',
    security: [['bearerAuth' => []]],
    tags: ['Security Policy'],
    parameters: [
        new OA\Parameter(name: 'roleKey', description: 'Must be an existing role key', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['super_admin', 'admin', 'manager', 'moderator']), example: 'moderator'),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(required: ['enabled'], properties: [
            new OA\Property(property: 'enabled', type: 'boolean', example: false),
        ])
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: '2FA policy updated',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'role', type: 'string', example: 'moderator'),
                    new OA\Property(property: 'mfa_required', type: 'boolean', example: false),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `settings.manage`, which the `admin` baseline deliberately excludes', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — unknown role key', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Patch(
    path: '/admin/settings/session-timeout',
    summary: 'Set the platform idle-session timeout (A.1c)',
    description: 'Applies to every admin without a per-account override. `0` disables idle expiry entirely — an explicit operator choice, not a default. Takes effect for tokens issued after the change.',
    security: [['bearerAuth' => []]],
    tags: ['Security Policy'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(required: ['minutes'], properties: [
            new OA\Property(property: 'minutes', type: 'integer', maximum: 1440, minimum: 0, example: 30),
        ])
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Session timeout updated',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'session_timeout_minutes', type: 'integer', example: 30),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `settings.manage`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]

// ------------------------------------------------------------------------ roles

#[OA\Get(
    path: '/admin/roles',
    summary: 'List roles with permission and admin counts',
    description: 'IT Admin login only — `role:it_admin`, not just the `access.role_manage` permission, so Super Admin\'s blanket bypass does not reach this screen. `super_admin`\'s own definition is never listed, for the same reason: it "cannot be scoped or limited", so there is nothing here to manage.',
    security: [['bearerAuth' => []]],
    tags: ['Roles'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'integer', example: 4),
                    new OA\Property(property: 'key', type: 'string', example: 'moderator'),
                    new OA\Property(property: 'name', type: 'string', example: 'Moderator'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'is_system', type: 'boolean', example: true),
                    new OA\Property(property: 'permission_count', type: 'integer', example: 5),
                    new OA\Property(property: 'admin_count', type: 'integer', example: 3),
                ])),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `access.role_manage` and the `it_admin` role', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/roles/{role}',
    summary: 'One role and its full permission-key list',
    description: 'IT Admin login only (see the list operation above). Requesting `super_admin`\'s id returns 404 rather than 403, so the response does not confirm the role exists.',
    security: [['bearerAuth' => []]],
    tags: ['Roles'],
    parameters: [
        new OA\Parameter(name: 'role', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 4),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'key', type: 'string'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'is_system', type: 'boolean'),
                    new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string')),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/roles',
    summary: 'Create a custom role',
    description: 'The escalation rule applies to baselines too: a non-Super-Admin cannot put a permission into a role that they do not themselves hold.',
    security: [['bearerAuth' => []]],
    tags: ['Roles'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(required: ['key', 'name'], properties: [
            new OA\Property(property: 'key', type: 'string', pattern: '^[a-z][a-z0-9_]*$', example: 'night_moderator'),
            new OA\Property(property: 'name', type: 'string', example: 'Night Moderator'),
            new OA\Property(property: 'description', type: 'string', nullable: true),
            new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), example: ['rooms.view', 'rooms.monitor_live', 'reports.view']),
        ])
    ),
    responses: [
        new OA\Response(response: 201, description: 'Role created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`PERMISSION_ESCALATION_DENIED` — a listed permission is not held by the caller', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — duplicate key, bad key format, or unknown permission', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Patch(
    path: '/admin/roles/{role}',
    summary: 'Rename a role or replace its baseline',
    description: 'Replacing `permissions` rebuilds the baseline wholesale and **flushes the cached permission set of every holder**, so the change is enforced on their next request. The Super Admin baseline cannot be edited.',
    security: [['bearerAuth' => []]],
    tags: ['Roles'],
    parameters: [
        new OA\Parameter(name: 'role', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 4),
    ],
    requestBody: new OA\RequestBody(
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'description', type: 'string', nullable: true),
            new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string')),
        ])
    ),
    responses: [
        new OA\Response(response: 200, description: 'Role updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — attempted to edit the Super Admin baseline', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 403, description: '`PERMISSION_ESCALATION_DENIED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Delete(
    path: '/admin/roles/{role}',
    summary: 'Delete a custom role',
    security: [['bearerAuth' => []]],
    tags: ['Roles'],
    parameters: [
        new OA\Parameter(name: 'role', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Role deleted', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — system roles cannot be deleted, or admins are still assigned (`details.admin_count`)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]

// ------------------------------------------------------------------ permissions

#[OA\Get(
    path: '/admin/permissions',
    summary: 'The full permission catalogue, grouped by module',
    description: '79 keys across 18 modules. Adding a key is a seeder change; removing one requires a data migration that revokes it everywhere first.',
    security: [['bearerAuth' => []]],
    tags: ['Permissions'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'modules', type: 'array', items: new OA\Items(ref: '#/components/schemas/ModuleGroup')),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `access.permission_grant`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/permissions/grantable',
    summary: 'Only what the caller may delegate (GFT-119)',
    description: 'The panel builds its grant UI from this. **It is a convenience, never the enforcement point** — the grant endpoint re-checks everything server-side, because the panel can be bypassed. A Manager gets `can_delegate: false` and an empty role list even though they hold permissions.',
    security: [['bearerAuth' => []]],
    tags: ['Permissions'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'can_delegate', type: 'boolean', example: true),
                    new OA\Property(property: 'grantable_to_roles', type: 'array', items: new OA\Items(type: 'string'), example: ['manager', 'moderator']),
                    new OA\Property(property: 'modules', type: 'array', items: new OA\Items(ref: '#/components/schemas/ModuleGroup')),
                    new OA\Property(property: 'total', type: 'integer', example: 76),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `access.permission_grant`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
class AccessPaths
{
}
