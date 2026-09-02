<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/** OpenAPI operations for epic A.9 — EventController and RankingController. */
#[OA\Get(
    path: '/admin/events',
    summary: 'List events, tournaments and lucky draws (A.9a)',
    description: <<<'MD'
Each row carries both `status` and `phase`. **`status` is the operator's intent**
(`draft`, `scheduled`, `cancelled`); **`phase` is what is actually true right now**
(`upcoming`, `live`, `ended`), derived from the clock at read time.

A.9a requires those transitions to happen "with no manual step", so nothing writes them —
a job that flipped a column would strand events in the wrong state whenever the scheduler
stalled. Filtering by `phase` evaluates the same window in SQL, so the list agrees with
what a reader would compute.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Events'],
    parameters: [
        new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['event', 'tournament', 'lucky_draw'])),
        new OA\Parameter(name: 'phase', in: 'query', schema: new OA\Schema(type: 'string', enum: ['draft', 'upcoming', 'live', 'ended', 'cancelled'])),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/EventRow')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `events.view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/events/{event}',
    summary: 'One event with its rewards, participants and draw',
    description: 'Reward bands report `payable`: coins and diamonds move through the wallet ledger, but frames, badges and VIP days have no inventory table until D.7, so those are recorded as claims rather than reported as granted.',
    security: [['bearerAuth' => []]],
    tags: ['Events'],
    parameters: [new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/events',
    summary: 'Create an event',
    description: 'Created as a `draft`. Creating a `lucky_draw` also commits to a seed immediately — before anyone can enter — and publishes only its hash.',
    security: [['bearerAuth' => []]],
    tags: ['Events'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['type', 'title_en', 'starts_at', 'ends_at'],
        properties: [
            new OA\Property(property: 'type', type: 'string', enum: ['event', 'tournament', 'lucky_draw']),
            new OA\Property(property: 'title_en', type: 'string', example: 'Diwali Gifting Marathon'),
            new OA\Property(property: 'title_hi', type: 'string', nullable: true),
            new OA\Property(property: 'description', type: 'string', nullable: true),
            new OA\Property(property: 'banner_url', type: 'string', nullable: true),
            new OA\Property(property: 'entry_type', type: 'string', enum: ['free', 'coins', 'invite']),
            new OA\Property(property: 'entry_cost', type: 'integer'),
            new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', description: 'Must be after starts_at'),
            new OA\Property(property: 'max_participants', type: 'integer', nullable: true),
            new OA\Property(property: 'winner_count', type: 'integer', description: 'Lucky draws only'),
            new OA\Property(property: 'algorithm', type: 'string', enum: ['random', 'weighted'], description: 'Lucky draws only'),
        ]
    )),
    responses: [
        new OA\Response(response: 201, description: 'Created as a draft', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `events.manage`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Patch(
    path: '/admin/events/{event}',
    summary: 'Edit an event',
    description: 'A finished event\'s dates cannot be moved — that would rewrite something that already happened.',
    security: [['bearerAuth' => []]],
    tags: ['Events'],
    parameters: [new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(type: 'object')),
    responses: [
        new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — the event has ended', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/events/{event}/publish',
    summary: 'Publish a draft',
    description: 'After this the clock decides the phase, not an operator.',
    security: [['bearerAuth' => []]],
    tags: ['Events'],
    parameters: [new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'Published', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — not a draft, or already over', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/events/{event}/cancel',
    summary: 'Cancel an event',
    description: 'Operator intent beats the clock: a cancelled event reports `cancelled` even while its window is open.',
    security: [['bearerAuth' => []]],
    tags: ['Events'],
    parameters: [new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
        new OA\Property(property: 'reason', type: 'string', maxLength: 500),
    ])),
    responses: [new OA\Response(response: 200, description: 'Cancelled', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/events/{event}/rewards',
    summary: 'Add a reward band (GFT-093)',
    description: 'Ranges must not overlap — otherwise "the reward for their band" is ambiguous. `quantity: null` means as many as the band holds.',
    security: [['bearerAuth' => []]],
    tags: ['Events'],
    parameters: [new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['rank_from', 'rank_to', 'reward_type', 'reward_value'],
        properties: [
            new OA\Property(property: 'rank_from', type: 'integer', minimum: 1, example: 1),
            new OA\Property(property: 'rank_to', type: 'integer', example: 3),
            new OA\Property(property: 'reward_type', type: 'string', enum: ['coins', 'diamonds', 'frame', 'badge', 'vip_days']),
            new OA\Property(property: 'reward_value', type: 'integer', minimum: 1, example: 10000),
            new OA\Property(property: 'quantity', type: 'integer', nullable: true),
        ]
    )),
    responses: [
        new OA\Response(response: 201, description: 'Added', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `events.reward_manage`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` with `details.overlapping`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Delete(
    path: '/admin/events/{event}/rewards/{reward}',
    summary: 'Remove a reward band',
    description: 'Refused once anything has been paid from it.',
    security: [['bearerAuth' => []]],
    tags: ['Events'],
    parameters: [
        new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'reward', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ],
    responses: [new OA\Response(response: 200, description: 'Removed', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/events/{event}/distribute',
    summary: 'Hand out the rewards (A.9b)',
    description: <<<'MD'
Ranks participants by score, then pays each rank the band that covers it. Three guarantees:

- **Exactly the banded ranks.** With bands for 1–3 and 4–10 and fifty entrants, ten people
  are eligible; the other forty get nothing.
- **The right band.** Matched on the final rank.
- **Once.** A unique index on (event, user) means a second run — or two concurrent runs —
  pays nothing further. Re-running is safe and reports what it skipped.

Only after the event has ended. Coins and diamonds move through the wallet ledger like any
other credit.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Events'],
    parameters: [new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Distributed',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'eligible', type: 'integer', example: 10),
                    new OA\Property(property: 'granted', type: 'integer', example: 10),
                    new OA\Property(property: 'skipped', type: 'integer', description: 'Already rewarded, or the band is capped'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — the event has not ended, or has no bands', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/events/{event}/draw',
    summary: 'Run a lucky draw (GFT-095, A.9a)',
    description: <<<'MD'
Provable fairness by commit-reveal.

A random seed is generated when the draw is created and only its **SHA-256 is published**.
That hash is a promise: the operator has already chosen and cannot choose again once
entries are in. Running the draw publishes the raw seed, so anyone can hash it, check it
against the commitment, and recompute the winners.

Selection is therefore **deterministic given the seed** — Efraimidis–Spirakis over
`hash(seed:user_id)`, not `rand()` or `shuffle()`, whose internals vary between builds. A
result nobody outside can reproduce is a claim, not proof.

Refused before `draw_at`: running early would break the commitment while entries are open.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Events'],
    parameters: [new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'Drawn — the seed is now public', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — not due yet, already run, or nobody entered', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND` — this event is not a lucky draw', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/events/{event}/draw/verify',
    summary: 'Recompute a published draw from its seed',
    description: 'The check an outsider would run: does the seed hash to the published commitment, and do the winners fall out of it again? Readable by anyone who can see the event — that is the point.',
    security: [['bearerAuth' => []]],
    tags: ['Events'],
    parameters: [new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Verification result',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'valid', type: 'boolean'),
                    new OA\Property(property: 'hash_matches', type: 'boolean'),
                    new OA\Property(property: 'winners_match', type: 'boolean'),
                    new OA\Property(property: 'recomputed', type: 'array', items: new OA\Items(type: 'integer')),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — the draw has not run', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]

// -------------------------------------------------------------------- rankings

#[OA\Get(
    path: '/admin/ranking-rules',
    summary: 'Ranking rules (A.9c)',
    description: 'Also returns `computable_board_types` — only wealth and charm can be computed today; room and agency boards need modules that do not exist yet, so the panel can grey them out rather than showing a permanently empty board.',
    security: [['bearerAuth' => []]],
    tags: ['Rankings'],
    parameters: [new OA\Parameter(name: 'include_inactive', in: 'query', schema: new OA\Schema(type: 'boolean'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/ranking-rules',
    summary: 'Create a ranking rule',
    security: [['bearerAuth' => []]],
    tags: ['Rankings'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['key', 'board_type', 'period', 'metric'],
        properties: [
            new OA\Property(property: 'key', type: 'string', pattern: '^[a-z][a-z0-9_]*$', example: 'wealth_daily'),
            new OA\Property(property: 'board_type', type: 'string', enum: ['wealth', 'charm', 'room', 'agency']),
            new OA\Property(property: 'period', type: 'string', enum: ['daily', 'weekly', 'monthly', 'all_time']),
            new OA\Property(property: 'metric', type: 'string', enum: ['coins_spent', 'diamonds_earned']),
            new OA\Property(property: 'min_threshold', type: 'integer', description: 'Below this a user never appears, whatever their position', example: 1000),
            new OA\Property(property: 'top_n', type: 'integer', example: 100),
        ]
    )),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Patch(
    path: '/admin/ranking-rules/{rule}',
    summary: 'Update a ranking rule',
    security: [['bearerAuth' => []]],
    tags: ['Rankings'],
    parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(type: 'object')),
    responses: [new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/ranking-rules/{rule}/board',
    summary: 'The live board (A.9c)',
    description: '`min_threshold` is applied **inside the query**, so a user below it never appears even when there are open places above them. Filtering after ranking would let a quiet period promote an ineligible user. `source.live` is false until Redis ZSETs arrive with the realtime layer.',
    security: [['bearerAuth' => []]],
    tags: ['Rankings'],
    parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/ranking-rules/{rule}/snapshot',
    summary: 'Freeze the board for this period',
    description: 'docs/02 §8 — "the snapshot is the record; Redis is the working surface." Re-snapshotting the same period replaces it rather than duplicating.',
    security: [['bearerAuth' => []]],
    tags: ['Rankings'],
    parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'Snapshot taken', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/ranking-rules/{rule}/snapshots',
    summary: 'Snapshotted periods, and how many were paid',
    security: [['bearerAuth' => []]],
    tags: ['Rankings'],
    parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/ranking-rules/{rule}/rewards',
    summary: 'Reward bands for a rule',
    security: [['bearerAuth' => []]],
    tags: ['Rankings'],
    parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/ranking-rules/{rule}/rewards',
    summary: 'Add a reward band to a rule',
    security: [['bearerAuth' => []]],
    tags: ['Rankings'],
    parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['rank_from', 'rank_to', 'reward_type', 'reward_value'],
        properties: [
            new OA\Property(property: 'rank_from', type: 'integer', minimum: 1),
            new OA\Property(property: 'rank_to', type: 'integer'),
            new OA\Property(property: 'reward_type', type: 'string', enum: ['coins', 'diamonds']),
            new OA\Property(property: 'reward_value', type: 'integer', minimum: 1),
        ]
    )),
    responses: [
        new OA\Response(response: 201, description: 'Added', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — overlapping ranks', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/ranking-rules/{rule}/pay-rewards',
    summary: 'Pay a snapshotted period (A.9d)',
    description: <<<'MD'
**Idempotent.** A payout row is created under a unique index on (snapshot, user) *before*
any money moves, so re-running the job pays nothing further — the second run reports what
it skipped rather than double-paying.

Creating the row first also means a crash between the two steps leaves a `pending` record
rather than an untracked payment.

Requires a snapshot for the period; there is nothing to pay against otherwise.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Rankings'],
    parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'period_start', type: 'string', format: 'date', description: 'Defaults to the current period'),
    ])),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Paid',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'paid', type: 'integer'),
                    new OA\Property(property: 'skipped', type: 'integer', description: 'Already paid on an earlier run'),
                    new OA\Property(property: 'total_value', type: 'integer'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `rankings.reward_payout`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND` — no snapshot for that period', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
class EventPaths
{
}
