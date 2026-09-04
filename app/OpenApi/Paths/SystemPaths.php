<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operations for SystemLogController — the IT Admin debug-log viewer.
 *
 * Keep in sync with that controller by hand — see the note in AuthPaths.
 */
#[OA\Get(
    path: '/admin/system/logs/laravel',
    summary: 'Tail the Laravel debug log (IT Admin)',
    description: 'Reads the last 2 MB of storage/logs/laravel.log from disk and parses it into entries. Behind `system.logs_view` and the `it_admin` role itself — Super Admin\'s blanket permission bypass does not reach this route.',
    security: [['bearerAuth' => []]],
    tags: ['System'],
    parameters: [
        new OA\Parameter(name: 'level', in: 'query', schema: new OA\Schema(type: 'string', enum: ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG'])),
        new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'lines', in: 'query', description: 'Max entries returned, newest first', schema: new OA\Schema(type: 'integer', minimum: 10, maximum: 2000, default: 300)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'entries', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'timestamp', type: 'string', example: '2026-09-04 10:15:23'),
                        new OA\Property(property: 'level', type: 'string', example: 'ERROR'),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'stack', type: 'string', nullable: true),
                    ])),
                    new OA\Property(property: 'truncated', type: 'boolean', description: 'True when the file is bigger than the 2 MB tail window read'),
                    new OA\Property(property: 'file_size', type: 'integer'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `system.logs_view` and the `it_admin` role', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/system/logs/frontend',
    summary: 'List reported frontend (admin-web) errors (IT Admin)',
    security: [['bearerAuth' => []]],
    tags: ['System'],
    parameters: [
        new OA\Parameter(name: 'level', in: 'query', schema: new OA\Schema(type: 'string', enum: ['error', 'warning', 'info'])),
        new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(ref: '#/components/schemas/Envelope')
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `system.logs_view` and the `it_admin` role', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/system/logs/frontend',
    summary: 'Report a browser error from the admin panel itself',
    description: 'Self-service — no `system.logs_view` needed. The admin-web error boundary posts here so IT Admin can see what broke in someone else\'s browser; the reporter does not need to be able to read the list back.',
    security: [['bearerAuth' => []]],
    tags: ['System'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(required: ['message'], properties: [
            new OA\Property(property: 'level', type: 'string', enum: ['error', 'warning', 'info'], example: 'error'),
            new OA\Property(property: 'message', type: 'string', example: 'TypeError: Cannot read properties of undefined'),
            new OA\Property(property: 'stack', type: 'string', nullable: true),
            new OA\Property(property: 'source_url', type: 'string', nullable: true, example: '/rooms/42'),
            new OA\Property(property: 'meta', type: 'object', nullable: true),
        ])
    ),
    responses: [
        new OA\Response(response: 201, description: 'Logged', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
class SystemPaths
{
}
