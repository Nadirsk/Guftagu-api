<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/** OpenAPI operations for epic A.6 — GiftController and VipTierController. */
#[OA\Get(
    path: '/admin/gifts',
    summary: 'List gifts (GFT-056)',
    description: 'Each row carries a `state` object rather than a single "available" flag, because a gift can be unsellable for three different reasons — inactive, outside its window, or sold out — and the panel should say which.',
    security: [['bearerAuth' => []]],
    tags: ['Store'],
    parameters: [
        new OA\Parameter(name: 'q', description: 'Name or code', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'tier', in: 'query', schema: new OA\Schema(type: 'string', enum: ['basic', 'premium', 'luxury', 'legendary'])),
        new OA\Parameter(name: 'state', in: 'query', schema: new OA\Schema(type: 'string', enum: ['available', 'sold_out', 'scheduled', 'inactive'])),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GiftRow')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `gifts.view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/gifts',
    summary: 'Create a gift (A.6a)',
    description: <<<'MD'
Prices are **integer coins** — `99.5` is rejected, because half a coin is not
representable (docs/02 §15 rule 1).

Writing here **flushes the app catalogue cache immediately**, so a new gift is live at
once rather than after the 600 s TTL. A.6a allows either; immediate is better.

`stock` is `null` for an unlimited gift and a number for a limited drop. Setting
`is_limited` without a stock number defaults it to `0` (sold out) rather than leaving it
`null`, which would mean "unlimited" and could never sell out.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Store'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['code', 'name_en', 'coin_price', 'diamond_value'],
        properties: [
            new OA\Property(property: 'code', type: 'string', pattern: '^[a-z0-9_]+$', example: 'sports_car'),
            new OA\Property(property: 'name_en', type: 'string', example: 'Sports Car'),
            new OA\Property(property: 'name_hi', type: 'string', example: 'स्पोर्ट्स कार', nullable: true),
            new OA\Property(property: 'category_id', type: 'integer', nullable: true),
            new OA\Property(property: 'tier', type: 'string', enum: ['basic', 'premium', 'luxury', 'legendary']),
            new OA\Property(property: 'coin_price', type: 'integer', minimum: 1, example: 9999),
            new OA\Property(property: 'diamond_value', type: 'integer', minimum: 0, description: 'What the receiver earns; the spread is platform margin', example: 5000),
            new OA\Property(property: 'thumbnail_url', type: 'string', nullable: true),
            new OA\Property(property: 'animation_url', type: 'string', nullable: true),
            new OA\Property(property: 'animation_type', type: 'string', enum: ['lottie', 'svga', 'mp4'], nullable: true),
            new OA\Property(property: 'duration_ms', type: 'integer', maximum: 60000, nullable: true),
            new OA\Property(property: 'is_fullscreen', type: 'boolean'),
            new OA\Property(property: 'is_combo_enabled', type: 'boolean'),
            new OA\Property(property: 'max_combo', type: 'integer'),
            new OA\Property(property: 'required_vip_tier_id', type: 'integer', nullable: true),
            new OA\Property(property: 'is_limited', type: 'boolean'),
            new OA\Property(property: 'stock', type: 'integer', description: 'null = unlimited, 0 = sold out', nullable: true),
            new OA\Property(property: 'available_from', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'available_to', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'is_active', type: 'boolean'),
            new OA\Property(property: 'sort_order', type: 'integer'),
        ]
    )),
    responses: [
        new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `gifts.manage`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — duplicate code, or a fractional price', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Patch(
    path: '/admin/gifts/{gift}',
    summary: 'Update a gift',
    description: 'Setting `is_limited: false` clears `stock` — leaving a number on an unlimited gift would make it sell out anyway.',
    security: [['bearerAuth' => []]],
    tags: ['Store'],
    parameters: [new OA\Parameter(name: 'gift', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(type: 'object')),
    responses: [new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Delete(
    path: '/admin/gifts/{gift}',
    summary: 'Deactivate a gift',
    description: '**Deactivates rather than deletes.** Every past send references this row, so removing it would break the gifting history. The gift leaves the app catalogue immediately.',
    security: [['bearerAuth' => []]],
    tags: ['Store'],
    parameters: [new OA\Parameter(name: 'gift', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'Deactivated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/gifts/{gift}/restock',
    summary: 'Set a limited drop’s stock (GFT-059)',
    description: 'Behind `gifts.drop_manage`, separate from `gifts.manage`: pricing a gift and deciding how many exist are different decisions. Refused on an unlimited gift, which has no stock to set.',
    security: [['bearerAuth' => []]],
    tags: ['Store'],
    parameters: [new OA\Parameter(name: 'gift', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['stock'], properties: [
        new OA\Property(property: 'stock', type: 'integer', minimum: 0, example: 500),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Stock updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — the gift is not a limited drop', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/gifts/animation',
    summary: 'Upload a gift animation (GFT-057)',
    description: 'Accepts Lottie, SVGA or MP4 up to **10 MB**. A larger file is rejected with a message naming the limit, as A.6a requires. Stored on the public disk for now; docs/07 swaps in DO Spaces by changing the disk name.',
    security: [['bearerAuth' => []]],
    tags: ['Store'],
    requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
        mediaType: 'multipart/form-data',
        schema: new OA\Schema(required: ['file', 'type'], properties: [
            new OA\Property(property: 'file', type: 'string', format: 'binary'),
            new OA\Property(property: 'type', type: 'string', enum: ['lottie', 'svga', 'mp4']),
        ])
    )),
    responses: [
        new OA\Response(response: 200, description: 'Uploaded', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — over the size cap, or a rejected type', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/gift-categories',
    summary: 'Gift categories (GFT-058)',
    security: [['bearerAuth' => []]],
    tags: ['Store'],
    parameters: [new OA\Parameter(name: 'include_inactive', in: 'query', schema: new OA\Schema(type: 'boolean'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/gift-categories',
    summary: 'Create a gift category',
    security: [['bearerAuth' => []]],
    tags: ['Store'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['key', 'name_en'], properties: [
        new OA\Property(property: 'key', type: 'string', pattern: '^[a-z][a-z0-9_]*$'),
        new OA\Property(property: 'name_en', type: 'string'),
        new OA\Property(property: 'name_hi', type: 'string', nullable: true),
        new OA\Property(property: 'sort_order', type: 'integer'),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ])),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Patch(
    path: '/admin/gift-categories/{category}',
    summary: 'Update a gift category',
    security: [['bearerAuth' => []]],
    tags: ['Store'],
    parameters: [new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(type: 'object')),
    responses: [new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]

// ---------------------------------------------------------------------- VIP

#[OA\Get(
    path: '/admin/vip-tiers',
    summary: 'VIP tiers and the privilege catalogue (A.6c)',
    description: 'Also returns `privilege_catalogue` — the keys the app actually understands — so the panel builds its privileges matrix from the backend rather than a hardcoded list that would drift.',
    security: [['bearerAuth' => []]],
    tags: ['VIP'],
    parameters: [new OA\Parameter(name: 'include_inactive', in: 'query', schema: new OA\Schema(type: 'boolean'))],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'tiers', type: 'array', items: new OA\Items(ref: '#/components/schemas/VipTierRow')),
                    new OA\Property(property: 'privilege_catalogue', type: 'array', items: new OA\Items(type: 'object')),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `vip.view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/vip-tiers',
    summary: 'Create a VIP tier',
    description: 'Prices are **integer paise**, never rupees — ₹999 is `99900`. A `monthly_rupees` field is returned for display only. ⚠ CI-02: the seeded prices are placeholders pending client input.',
    security: [['bearerAuth' => []]],
    tags: ['VIP'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['level', 'name_en'], properties: [
        new OA\Property(property: 'level', type: 'integer', maximum: 20, minimum: 1, description: 'Unique'),
        new OA\Property(property: 'name_en', type: 'string', example: 'VIP Gold'),
        new OA\Property(property: 'name_hi', type: 'string', nullable: true),
        new OA\Property(property: 'monthly_price_paise', type: 'integer', example: 99900),
        new OA\Property(property: 'quarterly_price_paise', type: 'integer'),
        new OA\Property(property: 'yearly_price_paise', type: 'integer'),
        new OA\Property(property: 'coin_price', type: 'integer'),
        new OA\Property(property: 'privileges', type: 'array', items: new OA\Items(type: 'string'), description: 'Only keys from privilege_catalogue are accepted'),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ])),
    responses: [
        new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — duplicate level, unknown privilege, or a fractional price', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Patch(
    path: '/admin/vip-tiers/{tier}',
    summary: 'Update a VIP tier',
    security: [['bearerAuth' => []]],
    tags: ['VIP'],
    parameters: [new OA\Parameter(name: 'tier', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(type: 'object')),
    responses: [new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Delete(
    path: '/admin/vip-tiers/{tier}',
    summary: 'Deactivate a VIP tier',
    description: 'Deactivates rather than deletes: gifts, frames, effects and room themes point at tiers, and subscriptions will once purchases exist.',
    security: [['bearerAuth' => []]],
    tags: ['VIP'],
    parameters: [new OA\Parameter(name: 'tier', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'Deactivated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/cosmetics',
    summary: 'Frames, badges and entrance effects (A.6d)',
    description: 'VIP gates are reported as a **level** rather than a raw tier id, because a level is what an operator recognises.',
    security: [['bearerAuth' => []]],
    tags: ['VIP'],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/cosmetics/frames',
    summary: 'Create a frame',
    security: [['bearerAuth' => []]],
    tags: ['VIP'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'image_url', type: 'string', nullable: true),
        new OA\Property(property: 'animation_url', type: 'string', nullable: true),
        new OA\Property(property: 'source', type: 'string', enum: ['vip', 'event', 'purchase', 'admin']),
        new OA\Property(property: 'coin_price', type: 'integer'),
        new OA\Property(property: 'required_vip_tier_id', type: 'integer', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ])),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Patch(
    path: '/admin/cosmetics/frames/{frame}',
    summary: 'Update a frame',
    security: [['bearerAuth' => []]],
    tags: ['VIP'],
    parameters: [new OA\Parameter(name: 'frame', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(type: 'object')),
    responses: [new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/cosmetics/badges',
    summary: 'Create a badge',
    security: [['bearerAuth' => []]],
    tags: ['VIP'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['key', 'name_en'], properties: [
        new OA\Property(property: 'key', type: 'string', pattern: '^[a-z][a-z0-9_]*$'),
        new OA\Property(property: 'name_en', type: 'string'),
        new OA\Property(property: 'name_hi', type: 'string', nullable: true),
        new OA\Property(property: 'icon_url', type: 'string', nullable: true),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'is_auto_awarded', type: 'boolean'),
    ])),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/cosmetics/effects',
    summary: 'Create an entrance effect',
    security: [['bearerAuth' => []]],
    tags: ['VIP'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'animation_url', type: 'string', nullable: true),
        new OA\Property(property: 'animation_type', type: 'string', nullable: true),
        new OA\Property(property: 'duration_ms', type: 'integer', nullable: true),
        new OA\Property(property: 'trigger', type: 'string', enum: ['vip_entry', 'big_gift', 'level_up', 'event']),
        new OA\Property(property: 'required_vip_tier_id', type: 'integer', nullable: true),
        new OA\Property(property: 'min_gift_coin_value', type: 'integer', nullable: true),
    ])),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
class StorePaths
{
}
