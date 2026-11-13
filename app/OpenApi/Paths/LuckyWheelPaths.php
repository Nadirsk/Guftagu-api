<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/** OpenAPI operations for the admin Lucky Wheel — LuckyWheelController (prices, slots, icons). */
#[OA\Get(
    path: '/admin/lucky-wheels',
    summary: 'List both lucky wheels with their slots, chances and spin totals',
    description: 'Returns the Classic and Glory wheels. `payout_rate` is the expected coins returned per coin spent. `totals` sums every spin ever made on the wheel.',
    security: [['bearerAuth' => []]],
    tags: ['Lucky Wheel'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LuckyWheelAdmin')),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `wheel.view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Patch(
    path: '/admin/lucky-wheels/{wheel}',
    summary: 'Update a wheel: name, prices and on/off',
    description: 'Every field is optional. Prices are integer coins. Writes an audit log entry (`lucky_wheel.update`).',
    security: [['bearerAuth' => []]],
    tags: ['Lucky Wheel'],
    parameters: [
        new OA\Parameter(name: 'wheel', description: 'Wheel id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: 60, example: 'Classic'),
            new OA\Property(property: 'spin_cost', type: 'integer', minimum: 1, maximum: 10000000, example: 100),
            new OA\Property(property: 'multi_count', type: 'integer', minimum: 2, maximum: 100, example: 10),
            new OA\Property(property: 'multi_cost', type: 'integer', minimum: 1, maximum: 1000000000, example: 900),
            new OA\Property(property: 'is_active', type: 'boolean'),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'Wheel updated', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', ref: '#/components/schemas/LuckyWheelAdmin'),
        ])),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `wheel.manage`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND` — no such wheel', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_FAILED` — a field is out of range', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Put(
    path: '/admin/lucky-wheels/{wheel}/prizes/{position}',
    summary: 'Save one of the eight slots of a wheel',
    description: <<<'MD'
Slots are fixed positions 1–8 round the wheel, so a slot is saved in place: it is created on
first save and overwritten after that. It is never added or removed.

- For `coins` and `diamonds`, `reward_value` is required and is the amount paid.
- For item types, `store_item_id` must point at a store item of that exact type. `reward_value`
  is forced to `1` and `duration_days` is optional.
- Any other type clears `store_item_id` and `duration_days`.

Writes an audit log entry (`lucky_wheel.prize_save`).
MD,
    security: [['bearerAuth' => []]],
    tags: ['Lucky Wheel'],
    parameters: [
        new OA\Parameter(name: 'wheel', description: 'Wheel id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'position', description: 'Slot number, 1–8', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 8)),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['reward_type', 'weight'],
        properties: [
            new OA\Property(property: 'label', type: 'string', maxLength: 40, nullable: true),
            new OA\Property(property: 'reward_type', type: 'string', description: 'One of the types in LuckyWheelPrize::types()', example: 'coins'),
            new OA\Property(property: 'reward_value', type: 'integer', minimum: 1, maximum: 1000000000, nullable: true, description: 'Required for coins and diamonds'),
            new OA\Property(property: 'store_item_id', type: 'integer', nullable: true, description: 'Required for item reward types'),
            new OA\Property(property: 'duration_days', type: 'integer', minimum: 1, maximum: 3650, nullable: true),
            new OA\Property(property: 'icon_url', type: 'string', format: 'uri', maxLength: 500, nullable: true, description: 'From POST /admin/lucky-wheels/icon'),
            new OA\Property(property: 'weight', type: 'integer', minimum: 0, maximum: 1000000, description: 'Relative chance in the draw; 0 means never drawn'),
            new OA\Property(property: 'is_active', type: 'boolean'),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'Slot saved; returns the whole wheel', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', ref: '#/components/schemas/LuckyWheelAdmin'),
        ])),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `wheel.manage`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND` — no such wheel, or position outside 1–8', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_FAILED` — bad reward type, or `store_item_id` is missing or of the wrong type', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/lucky-wheels/icon',
    summary: 'Upload an icon for a wheel slot',
    description: 'Multipart upload. Returns the stored URL, which is then passed as `icon_url` when saving a slot.',
    security: [['bearerAuth' => []]],
    tags: ['Lucky Wheel'],
    requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
        mediaType: 'multipart/form-data',
        schema: new OA\Schema(required: ['file'], properties: [
            new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'jpg, jpeg, png, webp or gif; max 2 MB'),
        ])
    )),
    responses: [
        new OA\Response(response: 200, description: 'Icon uploaded', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'object', description: 'Upload result: `url` and storage details'),
        ])),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `wheel.manage`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_FAILED` — missing file, wrong type, or larger than 2 MB', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Schema(
    schema: 'LuckyWheelAdmin',
    description: 'One wheel, as the admin panel sees it',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'mode', type: 'string', enum: ['classic', 'glory']),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'spin_cost', type: 'integer'),
        new OA\Property(property: 'multi_count', type: 'integer'),
        new OA\Property(property: 'multi_cost', type: 'integer'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'payout_rate', type: 'number', format: 'float', description: 'Expected coins back per coin spent'),
        new OA\Property(property: 'totals', type: 'object', properties: [
            new OA\Property(property: 'spins', type: 'integer'),
            new OA\Property(property: 'players', type: 'integer'),
            new OA\Property(property: 'coins_in', type: 'integer'),
            new OA\Property(property: 'coins_out', type: 'integer'),
        ]),
        new OA\Property(property: 'prizes', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'id', type: 'integer'),
            new OA\Property(property: 'position', type: 'integer', minimum: 1, maximum: 8),
            new OA\Property(property: 'label', type: 'string', nullable: true),
            new OA\Property(property: 'display_label', type: 'string'),
            new OA\Property(property: 'reward_type', type: 'string'),
            new OA\Property(property: 'reward_value', type: 'integer', nullable: true),
            new OA\Property(property: 'store_item_id', type: 'integer', nullable: true),
            new OA\Property(property: 'duration_days', type: 'integer', nullable: true),
            new OA\Property(property: 'icon_url', type: 'string', nullable: true),
            new OA\Property(property: 'weight', type: 'integer'),
            new OA\Property(property: 'is_active', type: 'boolean'),
            new OA\Property(property: 'payable', type: 'boolean'),
            new OA\Property(property: 'chance', type: 'number', format: 'float', description: 'Share of draws this slot wins'),
            new OA\Property(property: 'item', type: 'object', nullable: true),
        ])),
    ],
    type: 'object'
)]
final class LuckyWheelPaths
{
}
