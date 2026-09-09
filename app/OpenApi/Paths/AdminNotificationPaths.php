<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/** OpenAPI operations for the admin notification inbox (C.5a, general) — AdminNotificationController. */
#[OA\Get(
    path: '/admin/notifications',
    summary: 'Your own notification inbox (C.5a)',
    description: <<<'MD'
No permission key — every admin, any of the four roles, sees only their own rows. Support
escalation is one source today (`SupportService::escalate()`); more write to the same table
as they land.

`meta.unread_count` is the true unread total, not the size of the returned page — the badge
in the panel reads it directly rather than counting the page.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Notifications'],
    parameters: [
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        new OA\Parameter(name: 'unread', in: 'query', schema: new OA\Schema(type: 'boolean')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'type', type: 'string', example: 'support_escalation'),
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'body', type: 'string'),
                    new OA\Property(property: 'data', type: 'object', nullable: true),
                    new OA\Property(property: 'deep_link', type: 'string', nullable: true),
                    new OA\Property(property: 'is_read', type: 'boolean'),
                    new OA\Property(property: 'read_at', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                ])),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
    ]
)]
#[OA\Post(
    path: '/admin/notifications/{notification}/read',
    summary: 'Mark one notification read',
    description: 'Scoped to the caller — marking another admin\'s notification read returns `FORBIDDEN`.',
    security: [['bearerAuth' => []]],
    tags: ['Notifications'],
    parameters: [new OA\Parameter(name: 'notification', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`FORBIDDEN` — it belongs to another admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/notifications/read-all',
    summary: 'Mark every unread notification read',
    description: 'Only the caller\'s own rows — another admin\'s unread notifications are untouched.',
    security: [['bearerAuth' => []]],
    tags: ['Notifications'],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
    ]
)]
class AdminNotificationPaths
{
}
