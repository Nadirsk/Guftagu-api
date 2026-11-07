<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

// Weekly Star — gift-scored 7-day rounds, Top 3 paid automatically. Keep in sync with
// App\Http\Controllers\Admin\WeeklyStarController by hand.

#[OA\Post(
    path: '/admin/weekly-stars',
    summary: 'Create a Weekly Star (draft)',
    description: <<<'MD'
Starts at 00:00 on `starts_on` and runs `weeks` rounds of 7 days. Creates the three
rank tiers (Top 1, Top 2, Top 3) the app's Reward tab shows. Publish it with
`POST /admin/events/{event}/publish` — only one Weekly Star may be published for any
given dates.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Weekly Star'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['title_en', 'starts_on', 'weeks'],
        properties: [
            new OA\Property(property: 'title_en', type: 'string', maxLength: 150),
            new OA\Property(property: 'title_hi', type: 'string', nullable: true),
            new OA\Property(property: 'description', type: 'string', nullable: true),
            new OA\Property(property: 'starts_on', type: 'string', format: 'date', description: 'Today or later'),
            new OA\Property(property: 'weeks', type: 'integer', minimum: 1, maximum: 52),
        ]
    )),
    responses: [
        new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/weekly-stars/{event}',
    summary: 'A Weekly Star as the admin page shows it',
    description: '`event`, the Event `gifts`, the Top 1–3 `tiers` with their rewards, every round (`upcoming`, `live`, `due`, `settled` with its standings) and the live round\'s top 10 `board`.',
    security: [['bearerAuth' => []]],
    tags: ['Weekly Star'],
    parameters: [new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 404, description: 'Not a gift-scored Weekly Star', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Patch(
    path: '/admin/weekly-stars/{event}',
    summary: 'Edit a Weekly Star',
    description: 'The start date can only move before the campaign starts; `weeks` may not cut into a round already under way.',
    security: [['bearerAuth' => []]],
    tags: ['Weekly Star'],
    parameters: [new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'title_en', type: 'string'),
            new OA\Property(property: 'title_hi', type: 'string', nullable: true),
            new OA\Property(property: 'description', type: 'string', nullable: true),
            new OA\Property(property: 'rules', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
            new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
            new OA\Property(property: 'weeks', type: 'integer', minimum: 1, maximum: 52),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 422, description: 'Validation failed, or another published Weekly Star overlaps', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Put(
    path: '/admin/weekly-stars/{event}/gifts',
    summary: 'Set the Event Gifts',
    description: 'Up to 3 gift ids, in tile order. Only sending these gifts scores, by coin value; an empty list means nothing scores.',
    security: [['bearerAuth' => []]],
    tags: ['Weekly Star'],
    parameters: [new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['gift_ids'],
        properties: [new OA\Property(property: 'gift_ids', type: 'array', maxItems: 3, items: new OA\Items(type: 'integer'))]
    )),
    responses: [
        new OA\Response(response: 200, description: 'Saved', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/weekly-stars/{event}/tiers/{tier}/rewards',
    summary: 'Add a reward to a Top N card',
    description: <<<'MD'
`coins`/`diamonds` take `reward_value`. `frame`, `entry_effect`, `chat_bubble` take a store
item `ref_id`, `vip` a VIP tier, `badge` a badge — with `duration_days` (required for VIP;
null = permanent otherwise). The reward catalog row is found or created automatically.
Remove with `DELETE /admin/events/{event}/tiers/{tier}/rewards/{reward}`.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Weekly Star'],
    parameters: [
        new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'tier', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['reward_type'],
        properties: [
            new OA\Property(property: 'reward_type', type: 'string', enum: ['coins', 'diamonds', 'frame', 'entry_effect', 'chat_bubble', 'vip', 'badge']),
            new OA\Property(property: 'ref_id', type: 'integer', nullable: true),
            new OA\Property(property: 'reward_value', type: 'integer', nullable: true, minimum: 1),
            new OA\Property(property: 'duration_days', type: 'integer', nullable: true, minimum: 1, maximum: 3650),
        ]
    )),
    responses: [
        new OA\Response(response: 201, description: 'Added', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/weekly-stars/{event}/settle',
    summary: 'Pay a finished round now (manual backup)',
    description: 'Rounds settle themselves every 15 minutes via `events:settle-weekly-star`. Running this again pays only anyone a previous run missed.',
    security: [['bearerAuth' => []]],
    tags: ['Weekly Star'],
    parameters: [new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['period_start'],
        properties: [new OA\Property(property: 'period_start', type: 'string', format: 'date-time')]
    )),
    responses: [
        new OA\Response(response: 200, description: 'Settled', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — the round has not finished', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
class WeeklyStarPaths
{
}
