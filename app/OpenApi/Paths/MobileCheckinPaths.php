<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operations for epic D.7c — App\Http\Controllers\Api\CheckinController. Same rule
 * as MobileAuthPaths.php: kept in sync with the controller, and covered by
 * OpenApiDocumentTest's mobile allowlist (routes/api.php `api/v1/checkin`).
 */
#[OA\Get(
    path: '/checkin',
    summary: 'The 7-day check-in streak calendar (D.7c)',
    description: 'Missing a calendar day resets `current_streak_day` to 1. Completing day 7 without a gap rolls straight into a new cycle at day 1 rather than stopping.',
    security: [['bearerAuth' => []]],
    tags: ['Checkin'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'current_streak_day', type: 'integer', minimum: 1, maximum: 7),
                    new OA\Property(property: 'claimed_today', type: 'boolean'),
                    new OA\Property(property: 'week', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'day', type: 'integer'),
                        new OA\Property(property: 'reward_type', type: 'string', nullable: true),
                        new OA\Property(property: 'reward_value', type: 'integer', nullable: true),
                        new OA\Property(property: 'icon_url', type: 'string', nullable: true),
                        new OA\Property(property: 'payable', type: 'boolean'),
                        new OA\Property(property: 'status', type: 'string', enum: ['claimed', 'today', 'locked', 'unavailable']),
                    ])),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/checkin',
    summary: "Claim today's reward (D.7c)",
    description: 'Coins/diamonds are credited to the wallet immediately. One claim per calendar day — a second call the same day returns 409.',
    security: [['bearerAuth' => []]],
    tags: ['Checkin'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Reward claimed',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Reward claimed'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'streak_day', type: 'integer'),
                    new OA\Property(property: 'reward_type', type: 'string'),
                    new OA\Property(property: 'reward_value', type: 'integer'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND` — no reward configured for today\'s day yet', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 409, description: '`ALREADY_CLAIMED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
class MobileCheckinPaths
{
}
