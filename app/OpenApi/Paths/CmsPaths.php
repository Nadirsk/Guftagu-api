<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/** OpenAPI operations for epic A.10 — ContentController, BroadcastController, ReportCentreController, AuditLogController. */
#[OA\Get(
    path: '/admin/content/banners',
    summary: 'Banners, with click counts per placement (A.10a)',
    description: <<<'MD'
`is_active` is the operator's intent; **`state` is what is true right now** — one of `off`,
`scheduled`, `live` or `expired`, derived from the window at read time.

A.10a asks that a banner scheduled 01–07 September be invisible before the 1st and hidden
after the 7th "with no manual step". Nothing flips a flag on a schedule: a job that did
would strand banners live whenever the scheduler stalled.

`click_rate` is null until something has been shown — zero would read as "shown and never
clicked", which is a different statement.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    parameters: [
        new OA\Parameter(name: 'placement', in: 'query', schema: new OA\Schema(type: 'string', enum: ['home_top', 'room_list', 'wallet', 'event'])),
        new OA\Parameter(name: 'state', in: 'query', schema: new OA\Schema(type: 'string', enum: ['live', 'scheduled', 'expired', 'off'])),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'banners', type: 'array', items: new OA\Items(ref: '#/components/schemas/BannerRow')),
                new OA\Property(property: 'by_placement', type: 'array', items: new OA\Items(type: 'object')),
            ], type: 'object'),
        ])),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `cms.banner_manage`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/content/banners',
    summary: 'Create a banner',
    description: 'A window that closes before it opens is refused (422) — it would never show, and nothing else would ever say why.',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['title', 'image_url', 'placement'],
        properties: [
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'image_url', type: 'string', format: 'uri'),
            new OA\Property(property: 'placement', type: 'string', enum: ['home_top', 'room_list', 'wallet', 'event']),
            new OA\Property(property: 'action_type', type: 'string', enum: ['none', 'url', 'room', 'event']),
            new OA\Property(property: 'action_value', type: 'string', nullable: true),
            new OA\Property(property: 'sort_order', type: 'integer'),
            new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'is_active', type: 'boolean'),
        ]
    )),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Patch(
    path: '/admin/content/banners/{banner}',
    summary: 'Edit a banner',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    parameters: [new OA\Parameter(name: 'banner', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BannerRow')),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Delete(
    path: '/admin/content/banners/{banner}',
    summary: 'Remove a banner',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    parameters: [new OA\Parameter(name: 'banner', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/content/announcements',
    summary: 'Announcements (A.10a)',
    description: 'Same derived `state` as banners. `bilingual` flags an announcement with no Hindi text — the app falls back to English for Hindi-speaking users, which is worth knowing before it ships.',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/content/announcements',
    summary: 'Create an announcement',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['title_en', 'body_en'],
        properties: [
            new OA\Property(property: 'title_en', type: 'string'),
            new OA\Property(property: 'title_hi', type: 'string', nullable: true),
            new OA\Property(property: 'body_en', type: 'string'),
            new OA\Property(property: 'body_hi', type: 'string', nullable: true),
            new OA\Property(property: 'type', type: 'string', enum: ['marquee', 'popup', 'banner']),
            new OA\Property(property: 'target_roles', type: 'array', items: new OA\Items(type: 'string'), description: 'Empty or omitted means everyone.'),
            new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
        ]
    )),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Patch(
    path: '/admin/content/announcements/{announcement}',
    summary: 'Edit an announcement',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    parameters: [new OA\Parameter(name: 'announcement', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Delete(
    path: '/admin/content/announcements/{announcement}',
    summary: 'Remove an announcement',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    parameters: [new OA\Parameter(name: 'announcement', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/content/pages',
    summary: 'CMS pages (A.10a)',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/content/pages',
    summary: 'Create a page',
    description: 'Created as an unpublished draft at version 0.',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['slug', 'title_en', 'content_en', 'type'],
        properties: [
            new OA\Property(property: 'slug', type: 'string', example: 'community-guidelines'),
            new OA\Property(property: 'title_en', type: 'string'),
            new OA\Property(property: 'title_hi', type: 'string', nullable: true),
            new OA\Property(property: 'content_en', type: 'string'),
            new OA\Property(property: 'content_hi', type: 'string', nullable: true),
            new OA\Property(property: 'type', type: 'string', enum: ['terms', 'privacy', 'faq', 'about', 'guidelines', 'help']),
        ]
    )),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/content/pages/{page}',
    summary: 'One page with its version history',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    parameters: [new OA\Parameter(name: 'page', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Patch(
    path: '/admin/content/pages/{page}',
    summary: 'Save a draft edit',
    description: 'Edits in place. Nothing is versioned until it is published.',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    parameters: [new OA\Parameter(name: 'page', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/content/pages/{page}/publish',
    summary: 'Publish the current text as a new version',
    description: <<<'MD'
**Publishing cuts a version; it never overwrites one.** Terms and privacy pages are what a
user consented to on a given date, so the text as it stood then has to remain retrievable.

Publishing text identical to the last version is refused with `NO_CHANGES` — a history
full of no-op entries makes the real change impossible to find, which for a legal page is
the entire reason the history exists.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    parameters: [new OA\Parameter(name: 'page', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`NO_CHANGES`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/content/pages/{page}/unpublish',
    summary: 'Stop serving a page',
    description: 'Refused for terms and privacy pages (`LEGAL_PAGE`) — the app has to show something. Publish a replacement instead.',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    parameters: [new OA\Parameter(name: 'page', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/content/pages/{page}/restore/{version}',
    summary: 'Roll back to an earlier version',
    description: 'The rollback is published as a **new** version rather than deleting the ones after it. Removing history to undo a mistake is how you end up unable to prove what a user agreed to.',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    parameters: [
        new OA\Parameter(name: 'page', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'version', in: 'path', required: true, description: 'The version row id, not its number.', schema: new OA\Schema(type: 'integer')),
    ],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/content/faqs',
    summary: 'FAQs',
    description: '`missing_hindi` counts active FAQs with no Hindi answer, rather than leaving somebody to notice it in the app.',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    parameters: [new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/content/faqs',
    summary: 'Create an FAQ',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['question_en', 'answer_en'],
        properties: [
            new OA\Property(property: 'category', type: 'string'),
            new OA\Property(property: 'question_en', type: 'string'),
            new OA\Property(property: 'question_hi', type: 'string', nullable: true),
            new OA\Property(property: 'answer_en', type: 'string'),
            new OA\Property(property: 'answer_hi', type: 'string', nullable: true),
        ]
    )),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/content/faqs/reorder',
    summary: 'Reorder FAQs',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'order', type: 'array', items: new OA\Items(type: 'integer'), description: 'FAQ ids in the order they should appear.'),
    ])),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Patch(
    path: '/admin/content/faqs/{faq}',
    summary: 'Edit an FAQ',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    parameters: [new OA\Parameter(name: 'faq', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Delete(
    path: '/admin/content/faqs/{faq}',
    summary: 'Remove an FAQ',
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    parameters: [new OA\Parameter(name: 'faq', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/broadcasts',
    summary: 'Broadcast campaigns (A.10a)',
    security: [['bearerAuth' => []]],
    tags: ['Campaigns'],
    parameters: [new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['draft', 'scheduled', 'sending', 'sent', 'cancelled', 'failed']))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/broadcasts/preview',
    summary: 'Size an audience before anything is sent (A.10a)',
    description: <<<'MD'
A.10a requires the audience count before sending, and the preview and the send resolve the
**same** query — otherwise the number would be a guess.

Two figures come back, not one:

- **`matched`** — how many users the segment covers.
- **`reachable_push`** — how many of those have an active device with a push token.

Reporting only the first would promise a reach the platform does not have. The `note` spells
out the gap in words when there is one.

An unrecognised filter key is **refused** (`UNKNOWN_FILTER`), never ignored: silently
dropping one would preview a wider audience than the operator asked for, and then send to it.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Campaigns'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['audience'],
        properties: [
            new OA\Property(property: 'audience', type: 'string', enum: ['all', 'segment', 'user_list']),
            new OA\Property(property: 'audience_filter', type: 'object', description: 'Keys from the published filter list, e.g. `recharged_within_days`.', example: ['recharged_within_days' => 30]),
            new OA\Property(property: 'user_ids', type: 'array', items: new OA\Items(type: 'integer')),
            new OA\Property(property: 'channels', type: 'array', items: new OA\Items(type: 'string', enum: ['push', 'in_app'])),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'matched', type: 'integer'),
                new OA\Property(property: 'reachable_push', type: 'integer'),
                new OA\Property(property: 'unreachable', type: 'integer'),
                new OA\Property(property: 'note', type: 'string', nullable: true),
                new OA\Property(property: 'sample', type: 'array', items: new OA\Items(type: 'object')),
            ], type: 'object'),
        ])),
        new OA\Response(response: 422, description: '`UNKNOWN_FILTER` or `FILTER_UNAVAILABLE`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/broadcasts/{broadcast}',
    summary: 'One campaign',
    description: 'A sent campaign reports the audience it went to, frozen. An unsent one reports what it would reach if sent now — those are different questions, so only one of them is answered.',
    security: [['bearerAuth' => []]],
    tags: ['Campaigns'],
    parameters: [new OA\Parameter(name: 'broadcast', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/broadcasts',
    summary: 'Create a campaign',
    description: 'The audience is resolved at creation, so an impossible filter is refused now rather than at 3am when the schedule fires.',
    security: [['bearerAuth' => []]],
    tags: ['Campaigns'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['title', 'body'],
        properties: [
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'body', type: 'string'),
            new OA\Property(property: 'image_url', type: 'string', nullable: true),
            new OA\Property(property: 'deep_link', type: 'string', nullable: true),
            new OA\Property(property: 'audience', type: 'string', enum: ['all', 'segment', 'user_list']),
            new OA\Property(property: 'audience_filter', type: 'object'),
            new OA\Property(property: 'channels', type: 'array', items: new OA\Items(type: 'string', enum: ['push', 'in_app'])),
            new OA\Property(property: 'scheduled_at', type: 'string', format: 'date-time', nullable: true),
        ]
    )),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Patch(
    path: '/admin/broadcasts/{broadcast}',
    summary: 'Edit a draft or scheduled campaign',
    security: [['bearerAuth' => []]],
    tags: ['Campaigns'],
    parameters: [new OA\Parameter(name: 'broadcast', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/broadcasts/{broadcast}/cancel',
    summary: 'Cancel an unsent campaign',
    security: [['bearerAuth' => []]],
    tags: ['Campaigns'],
    parameters: [new OA\Parameter(name: 'broadcast', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/broadcasts/{broadcast}/send',
    summary: 'Send a campaign (A.10a)',
    description: <<<'MD'
**What actually happens, stated plainly.** In-app notification rows are written for every
recipient, in chunks. The push leg is **not** dispatched: FCM delivery needs credentials
and a mobile app that registers tokens, both of which arrive with E.2c. The response says
`push_dispatched: false` and names how many devices *would* have been reachable.

`sent_count` records what was genuinely created, not the audience size. `delivered_count`
and `opened_count` stay at zero because nothing has reported back — inventing a delivery
rate would be worse than admitting there is not one yet.

The audience size is **frozen onto the row when it is sent**; re-counting later would
silently rewrite history as users sign up or churn.

Needs `cms.campaign_send`, which is deliberately separate from the compose permission.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Campaigns'],
    parameters: [new OA\Parameter(name: 'broadcast', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 409, description: '`ALREADY_SENT`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`EMPTY_AUDIENCE`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/reports-centre',
    summary: 'What can be reported on (A.10b)',
    description: 'Report types, their columns, the shared filter grammar and which permission governs each. PDF rendering is not built — CSV is the only format, and it is the one that survives a 200,000-row report.',
    security: [['bearerAuth' => []]],
    tags: ['Reports'],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/reports-centre/preview',
    summary: 'The first hundred rows, plus the true total',
    description: 'The total is a separate `COUNT`, not the length of the preview — otherwise a 200,000-row report would preview as "100 rows" and somebody would believe it. An unrecognised filter is refused (`UNKNOWN_FILTER`) rather than dropped.',
    security: [['bearerAuth' => []]],
    tags: ['Reports'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['type'],
        properties: [
            new OA\Property(property: 'type', type: 'string', enum: ['revenue', 'users', 'hosts', 'transactions']),
            new OA\Property(property: 'filters', type: 'object', example: ['from' => '2026-08-01', 'to' => '2026-08-31']),
        ]
    )),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/reports-centre/reconcile',
    summary: 'Does revenue agree with the ledger? (A.10b)',
    description: <<<'MD'
A.10b asks that a revenue report reconcile exactly with the ledger. The report is computed
**from the ledger**, not from the `daily_stats` rollup the dashboard reads — a rollup is a
convenience that can drift, and a financial report cannot be a convenience.

This endpoint compares the two and reports the difference. Neither is silently preferred:
`authoritative` names the ledger, and the note says to rebuild the rollup rather than to
doubt the report.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Reports'],
    parameters: [
        new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
    ],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/reports-centre/export',
    summary: 'Queue a CSV export (A.10c)',
    description: <<<'MD'
Genuinely queued: the request returns an id straight away and the panel polls until the row
turns `ready`.

The worker **streams** — rows come from a `LazyCollection` backed by keyset pagination and
go straight out through a file handle, so a 200,000-row export never accumulates in memory.
The file carries a UTF-8 BOM so Excel does not mangle Devanagari, and a failed export
deletes its partial file rather than leaving a truncated one to be downloaded and trusted.

Each type is gated on its own `reports_export.*` permission, checked against the request
body — somebody who may pull a user list is not thereby able to pull revenue.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Reports'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['type'],
        properties: [
            new OA\Property(property: 'type', type: 'string', enum: ['revenue', 'users', 'hosts', 'transactions']),
            new OA\Property(property: 'format', type: 'string', enum: ['csv']),
            new OA\Property(property: 'filters', type: 'object'),
        ]
    )),
    responses: [new OA\Response(response: 202, description: 'Queued', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/reports-centre/exports',
    summary: 'The download centre',
    description: '`downloadable` accounts for expiry: a row can say `ready` while the file has aged out from under it.',
    security: [['bearerAuth' => []]],
    tags: ['Reports'],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/reports-centre/exports/{uuid}/download',
    summary: 'Download a finished export',
    description: 'Exports of financial and personal data are kept for seven days, then refused with `EXPIRED` (410). Another admin\'s export returns 404 — holding the id is not authorisation.',
    security: [['bearerAuth' => []]],
    tags: ['Reports'],
    parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    responses: [
        new OA\Response(response: 200, description: 'The CSV'),
        new OA\Response(response: 409, description: '`NOT_READY`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 410, description: '`EXPIRED` or `FILE_MISSING`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/audit-logs',
    summary: 'Search the audit trail (A.10d)',
    description: <<<'MD'
Append-only. There is deliberately no write endpoint here, and no delete.

`source` says where a row came from: **`service`** rows were written by the code that made
the change and carry a real before/after; **`middleware`** rows were caught by the
safety net, which can only see a request, and carry no diff. Knowing which is which matters
before trusting a diff that is not there.

`before`/`after` are omitted from the list — a permission grant's payload is large, and a
hundred of them would make the list unusable. The detail endpoint carries the diff.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Audit'],
    parameters: [
        new OA\Parameter(name: 'admin_user_id', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'module', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'action', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'entity_type', in: 'query', description: 'Matches the tail, so `User` and `App\Models\User` both work.', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'entity_id', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'q', in: 'query', description: 'Action, entity id, IP or request id.', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'source', in: 'query', schema: new OA\Schema(type: 'string', enum: ['service', 'middleware'])),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AuditLogRow')),
            new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
        ])),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `access.audit_view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/audit-logs/filters',
    summary: 'What the viewer can filter on',
    security: [['bearerAuth' => []]],
    tags: ['Audit'],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/audit-logs/coverage',
    summary: 'How much of the trail is a real diff',
    description: 'A.10d is only satisfied in spirit if the rows are useful. A module showing mostly `middleware` rows has endpoints that mutate without saying what they changed — this makes that visible instead of leaving it to be discovered during an incident.',
    security: [['bearerAuth' => []]],
    tags: ['Audit'],
    parameters: [new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/audit-logs/entity',
    summary: 'Everything that happened to one entity',
    description: 'Oldest first — the history of a user, a settlement, a role.',
    security: [['bearerAuth' => []]],
    tags: ['Audit'],
    parameters: [
        new OA\Parameter(name: 'entity_type', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: 'User')),
        new OA\Parameter(name: 'entity_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/audit-logs/{log}',
    summary: 'One entry with its field-level diff',
    description: <<<'MD'
`changes` lists only the keys that actually moved. A service logs a partial `before` — the
fields it was about to touch — so a naive union would report every unmentioned key as "set
from nothing", which is noise rather than a change. `kind` says whether a one-sided entry
is a genuine `set` or merely `recorded_before_only`.

`related` lists everything that happened in the same HTTP request. A single ban can produce
a sanction row and a wallet freeze; seeing them together is the point.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Audit'],
    parameters: [new OA\Parameter(name: 'log', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Schema(
    schema: 'BannerRow',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'image_url', type: 'string'),
        new OA\Property(property: 'placement', type: 'string', enum: ['home_top', 'room_list', 'wallet', 'event']),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', description: 'The operator intent.'),
        new OA\Property(property: 'state', type: 'string', enum: ['off', 'scheduled', 'live', 'expired'], description: 'What is true right now, derived from the window.'),
        new OA\Property(property: 'is_live', type: 'boolean'),
        new OA\Property(property: 'click_count', type: 'integer'),
        new OA\Property(property: 'impression_count', type: 'integer'),
        new OA\Property(property: 'click_rate', type: 'number', nullable: true, description: 'Null until something has been shown.'),
    ]
)]
#[OA\Schema(
    schema: 'AuditLogRow',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'actor', type: 'string', description: '`system` when no admin was attached.'),
        new OA\Property(property: 'action', type: 'string'),
        new OA\Property(property: 'module', type: 'string'),
        new OA\Property(property: 'entity_type', type: 'string', nullable: true),
        new OA\Property(property: 'entity_id', type: 'string', nullable: true),
        new OA\Property(property: 'ip', type: 'string', nullable: true),
        new OA\Property(property: 'request_id', type: 'string', nullable: true),
        new OA\Property(property: 'source', type: 'string', enum: ['service', 'middleware']),
        new OA\Property(property: 'has_diff', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
class CmsPaths
{
}
