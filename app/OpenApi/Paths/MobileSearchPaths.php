<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operations for epic D.3a, GFT-222 — App\Http\Controllers\Api\SearchController.
 *
 * Kept separate from MobileAuthPaths.php — same reasoning as that file: **if you change a
 * route, request or response in SearchController, update this file in the same commit.**
 * Throttled at 30/min/user (docs/03 §16) — its own limiter, separate from the general
 * mobile-api one.
 */
#[OA\Get(
    path: '/search',
    summary: 'Search people and rooms (D.3a)',
    description: '`remember=true` records the submitted term in search history; the search box calls this endpoint on every keystroke, so only a submitted search or a tapped result should set that flag.',
    security: [['bearerAuth' => []]],
    tags: ['Search'],
    parameters: [
        new OA\Parameter(name: 'q', in: 'query', required: true, schema: new OA\Schema(type: 'string', maxLength: 100)),
        new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['users', 'rooms', 'all'], default: 'all')),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 20)),
        new OA\Parameter(name: 'remember', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'users', type: 'array', items: new OA\Items(ref: '#/components/schemas/SocialUserCard')),
                    new OA\Property(property: 'rooms', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'room_code', type: 'string', example: 'RM001000'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'cover_url', type: 'string', nullable: true),
                        new OA\Property(property: 'status', type: 'string', enum: ['live', 'idle', 'closed', 'force_closed']),
                        new OA\Property(property: 'listener_count', type: 'integer'),
                        new OA\Property(property: 'category', type: 'string', nullable: true),
                        new OA\Property(property: 'owner', ref: '#/components/schemas/SocialUserCard'),
                    ])),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 429, description: '`RATE_LIMITED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/search/history',
    summary: "The caller's recent searches (D.3a)",
    security: [['bearerAuth' => []]],
    tags: ['Search'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'type', type: 'string', enum: ['term', 'user', 'room']),
                    new OA\Property(property: 'term', type: 'string'),
                    new OA\Property(property: 'target_uuid', type: 'string', format: 'uuid', description: 'Set when the entry is a tapped user/room rather than a typed term', nullable: true),
                    new OA\Property(property: 'searched_at', type: 'string', format: 'date-time', nullable: true),
                ])),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/search/history',
    summary: 'Record a submitted search or a tapped result (D.3a)',
    security: [['bearerAuth' => []]],
    tags: ['Search'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['term'], properties: [
        new OA\Property(property: 'term', type: 'string', maxLength: 100),
        new OA\Property(property: 'type', type: 'string', enum: ['term', 'user', 'room'], default: 'term'),
        new OA\Property(property: 'target_uuid', type: 'string', format: 'uuid', nullable: true),
    ])),
    responses: [
        new OA\Response(
            response: 201,
            description: 'Saved',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Saved'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Delete(
    path: '/search/history',
    summary: 'Clear all search history (D.3a)',
    security: [['bearerAuth' => []]],
    tags: ['Search'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'History cleared',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'History cleared'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'deleted', type: 'integer'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Delete(
    path: '/search/history/{uuid}',
    summary: 'Remove one search-history entry (D.3a)',
    security: [['bearerAuth' => []]],
    tags: ['Search'],
    parameters: [
        new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Removed', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
class MobileSearchPaths
{
}
