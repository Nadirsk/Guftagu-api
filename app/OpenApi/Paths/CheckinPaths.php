<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operations for epic D.7c — App\Http\Controllers\Admin\CheckinRewardController.
 *
 * Same rule as every other Paths file: **if you change a route, request or response in
 * CheckinRewardController, update this file in the same commit.**
 */
#[OA\Get(
    path: '/admin/checkin-rewards',
    summary: 'The 7-day check-in reward ladder (D.7c)',
    description: 'Always up to 7 rows, one per `streak_day`. `payable` is true only for `coins`/`diamonds` right now — cosmetic reward types would need an inventory table that does not exist yet, so they are deliberately not offered.',
    security: [['bearerAuth' => []]],
    tags: ['Checkin'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CheckinRewardRow')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `checkin.view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/checkin-rewards',
    summary: 'Add a day\'s reward',
    description: 'Refused with 422 if that `streak_day` already has a reward — use PATCH to change one.',
    security: [['bearerAuth' => []]],
    tags: ['Checkin'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['streak_day', 'reward_type', 'reward_value'],
            properties: [
                new OA\Property(property: 'streak_day', type: 'integer', minimum: 1, maximum: 7, description: 'Unique'),
                new OA\Property(property: 'reward_type', type: 'string', enum: ['coins', 'diamonds']),
                new OA\Property(property: 'reward_value', type: 'integer', minimum: 1),
                new OA\Property(property: 'icon_url', type: 'string', format: 'uri', nullable: true),
                new OA\Property(property: 'is_active', type: 'boolean', default: true),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — that day already has a reward', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Patch(
    path: '/admin/checkin-rewards/{checkinReward}',
    summary: 'Update a day\'s reward',
    security: [['bearerAuth' => []]],
    tags: ['Checkin'],
    parameters: [new OA\Parameter(name: 'checkinReward', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(type: 'object')),
    responses: [new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Delete(
    path: '/admin/checkin-rewards/{checkinReward}',
    summary: 'Remove a day\'s reward',
    description: 'Leaves that `streak_day` unconfigured — a user who reaches it gets `NOT_FOUND` on claim until it is set again.',
    security: [['bearerAuth' => []]],
    tags: ['Checkin'],
    parameters: [new OA\Parameter(name: 'checkinReward', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'Removed', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Schema(
    schema: 'CheckinRewardRow',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'streak_day', type: 'integer'),
        new OA\Property(property: 'reward_type', type: 'string'),
        new OA\Property(property: 'reward_value', type: 'integer'),
        new OA\Property(property: 'icon_url', type: 'string', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'payable', type: 'boolean'),
    ]
)]
class CheckinPaths
{
}
