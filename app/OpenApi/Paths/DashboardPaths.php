<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/** OpenAPI operations for epic A.2 — DashboardController. */
#[OA\Get(
    path: '/admin/dashboard/kpis',
    summary: 'Live counters (A.2a)',
    description: <<<'MD'
Cached for 10 seconds, so a room full of admins polling this is one query rather than
dozens. The panel refreshes it every 5 seconds, which satisfies A.2a's "updates within 5
seconds without a page reload".

`rooms.available` is **false** until the rooms module lands — the tile says "not built yet"
rather than reporting zero live rooms, which would be a different and untrue claim.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Dashboard'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'users', type: 'object', properties: [
                        new OA\Property(property: 'total', type: 'integer', example: 8),
                        new OA\Property(property: 'active', type: 'integer'),
                        new OA\Property(property: 'suspended', type: 'integer'),
                        new OA\Property(property: 'banned', type: 'integer'),
                        new OA\Property(property: 'new_today', type: 'integer'),
                        new OA\Property(property: 'new_7d', type: 'integer'),
                    ]),
                    new OA\Property(property: 'engagement', type: 'object', properties: [
                        new OA\Property(property: 'active_today', type: 'integer'),
                        new OA\Property(property: 'active_30d', type: 'integer'),
                        new OA\Property(property: 'dau_mau_ratio', type: 'number', format: 'float', example: 0.42),
                    ]),
                    new OA\Property(property: 'queues', type: 'object', properties: [
                        new OA\Property(property: 'kyc_pending', type: 'integer', example: 2),
                    ]),
                    new OA\Property(property: 'rooms', type: 'object', properties: [
                        new OA\Property(property: 'live', type: 'integer', example: 0),
                        new OA\Property(property: 'available', type: 'boolean', example: false),
                    ]),
                    new OA\Property(property: 'as_of', type: 'string', format: 'date-time'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `dashboard.view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/dashboard/revenue',
    summary: 'Revenue by stream (A.2b)',
    description: <<<'MD'
Read entirely from the `daily_stats` rollup — **no query here scans a ledger**, which is
A.2's NFR and is enforced by a test that query-logs this endpoint.

Streams are reported separately and `coin_total` is their exact sum for the range, as A.2b
requires. `streams_live` says which are real yet: recharge, gifting and VIP stay at zero
until payments and gifting land, so a flat line there means "not built", not "no revenue".

A range longer than 400 days is clamped; a reversed range is swapped.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Dashboard'],
    parameters: [
        new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'granularity', in: 'query', schema: new OA\Schema(type: 'string', enum: ['day', 'week', 'month'], default: 'day')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'series', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'totals', type: 'object'),
                    new OA\Property(property: 'coin_total', type: 'integer', description: 'Exactly recharge + gifting + vip + other'),
                    new OA\Property(property: 'streams_live', type: 'object', description: 'Which streams have a source yet'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
    ]
)]
#[OA\Get(
    path: '/admin/dashboard/engagement',
    summary: 'Signups, activity and retention (A.2c)',
    description: <<<'MD'
`retention.measure` is **`still_active_after`, not day-N retention.** Textbook D1/D7/D30
asks whether someone was active *on* day N, which needs a per-day activity record; the only
activity signal today is `users.last_active_at`, so what is reported is the share of each
signup cohort last seen at least N days after joining. The response carries a `note` saying
so — shipping a familiar name over a different number would be worse than the gap.

`total_users` is a running total, so week and month buckets take its last value rather than
summing it.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Dashboard'],
    parameters: [
        new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'granularity', in: 'query', schema: new OA\Schema(type: 'string', enum: ['day', 'week', 'month'], default: 'day')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'series', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'retention', type: 'object', properties: [
                        new OA\Property(property: 'measure', type: 'string', example: 'still_active_after'),
                        new OA\Property(property: 'note', type: 'string'),
                        new OA\Property(property: 'cohorts', type: 'array', items: new OA\Items(type: 'object')),
                    ]),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
    ]
)]
#[OA\Post(
    path: '/admin/dashboard/export',
    summary: 'Queue a CSV export (A.2d)',
    description: 'Returns `202` immediately with a uuid — the file is built by a queue worker, so the caller is never blocked. Poll `/admin/dashboard/exports` until the row turns `ready`, then download it. **A worker must be running** (`php artisan queue:work`) or the row stays `queued`.',
    security: [['bearerAuth' => []]],
    tags: ['Dashboard'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['type'], properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['revenue']),
        new OA\Property(property: 'format', type: 'string', enum: ['csv'], default: 'csv'),
        new OA\Property(property: 'from', type: 'string', format: 'date'),
        new OA\Property(property: 'to', type: 'string', format: 'date'),
    ])),
    responses: [
        new OA\Response(response: 202, description: 'Queued', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `dashboard.export`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/dashboard/exports',
    summary: 'Your recent exports (GFT-022)',
    security: [['bearerAuth' => []]],
    tags: ['Dashboard'],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
    ]
)]
#[OA\Get(
    path: '/admin/dashboard/exports/{export}/download',
    summary: 'Download a finished export',
    description: 'Scoped to the admin who requested it — an export can hold a month of financial data, so holding its id is not authorisation to read it.',
    security: [['bearerAuth' => []]],
    tags: ['Dashboard'],
    parameters: [new OA\Parameter(name: 'export', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'The CSV file', content: new OA\MediaType(mediaType: 'text/csv')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — not finished; `details.status` says where it is', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 403, description: '`FORBIDDEN` — it belongs to another admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND` — the file has been cleaned up', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
class DashboardPaths
{
}
