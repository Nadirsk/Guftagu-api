<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/** OpenAPI operations for epic A.7 — EconomyController and WithdrawalController. */
#[OA\Get(
    path: '/admin/economy/rates',
    summary: 'Conversion rates and their history (A.7a)',
    description: <<<'MD'
A rate is a **fraction**, not a decimal: `1/2` is exact where `0.5` is exact only by luck
and `1/3` is not representable at all. Conversion multiplies then divides with integer
arithmetic, rounding **down** — a deliberate policy choice, since a fraction of a paise
should not become a paise in the payee's favour on every request.

Rates are **effective-dated and never edited**. Setting a new one closes the current row
and opens another, so the timeline has no gap and history stays readable. A withdrawal
stores the numerator and denominator it was priced at, so approving it next week settles
at the rate it was raised at — A.7a's "historical requests are never re-priced".

⚠ CI-01 supplies the real rates; the seeded ones are placeholders.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Economy'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Current rate plus the full timeline per key',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'rates', type: 'object'),
                    new OA\Property(property: 'note', type: 'string'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `economy.ledger_view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Patch(
    path: '/admin/economy/rates',
    summary: 'Set a conversion rate',
    description: 'Supersedes the row in force rather than editing it. Behind `economy.rates_manage`, which the Admin baseline deliberately excludes — only a Super Admin may reprice the economy.',
    security: [['bearerAuth' => []]],
    tags: ['Economy'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['key', 'rate_numerator', 'rate_denominator'],
        properties: [
            new OA\Property(property: 'key', type: 'string', enum: ['coin_to_diamond', 'diamond_to_inr']),
            new OA\Property(property: 'rate_numerator', type: 'integer', minimum: 1, example: 50),
            new OA\Property(property: 'rate_denominator', type: 'integer', minimum: 1, example: 1),
            new OA\Property(property: 'effective_from', type: 'string', format: 'date-time', description: 'Defaults to now; cannot be backdated behind a scheduled rate', nullable: true),
            new OA\Property(property: 'note', type: 'string', nullable: true),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'Rate updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `economy.rates_manage`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`RATE_CONFLICT` — a later rate is already scheduled', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/economy/packages',
    summary: 'Recharge packages (A.7a)',
    description: 'Prices are integer paise. `paise_per_coin` is derived so packages can be compared at a glance. ⚠ CI-01.',
    security: [['bearerAuth' => []]],
    tags: ['Economy'],
    parameters: [new OA\Parameter(name: 'include_inactive', in: 'query', schema: new OA\Schema(type: 'boolean'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/economy/packages',
    summary: 'Create a recharge package',
    security: [['bearerAuth' => []]],
    tags: ['Economy'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['name', 'coins', 'price_paise'],
        properties: [
            new OA\Property(property: 'name', type: 'string', example: 'Popular'),
            new OA\Property(property: 'coins', type: 'integer', minimum: 1, example: 500),
            new OA\Property(property: 'bonus_coins', type: 'integer', example: 50),
            new OA\Property(property: 'price_paise', type: 'integer', description: 'Rs 449 is 44900', example: 44900),
            new OA\Property(property: 'is_first_purchase_only', type: 'boolean'),
            new OA\Property(property: 'badge_text', type: 'string', nullable: true),
            new OA\Property(property: 'valid_from', type: 'string', format: 'date-time', nullable: true),
            new OA\Property(property: 'valid_to', type: 'string', format: 'date-time', nullable: true),
        ]
    )),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Patch(
    path: '/admin/economy/packages/{package}',
    summary: 'Update a recharge package',
    security: [['bearerAuth' => []]],
    tags: ['Economy'],
    parameters: [new OA\Parameter(name: 'package', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(type: 'object')),
    responses: [new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/economy/commission-slabs',
    summary: 'Commission slabs (A.7c)',
    description: 'Rates are **integer basis points** — 1250 is 12.50%. docs/02 puts it plainly: a float rate loses a rupee per thousand transactions and cannot be explained afterwards. ⚠ CI-02.',
    security: [['bearerAuth' => []]],
    tags: ['Economy'],
    parameters: [new OA\Parameter(name: 'applies_to', in: 'query', schema: new OA\Schema(type: 'string', enum: ['platform', 'agency', 'host']))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/economy/commission-slabs',
    summary: 'Create a commission slab',
    description: 'Overlapping ranges are refused **naming the slabs they collide with** — "commission was wrong between 5,000 and 10,000" is a bug nobody finds for months. `max_value: null` means "and above".',
    security: [['bearerAuth' => []]],
    tags: ['Economy'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['applies_to', 'metric', 'min_value', 'percentage_bp'],
        properties: [
            new OA\Property(property: 'applies_to', type: 'string', enum: ['platform', 'agency', 'host']),
            new OA\Property(property: 'agency_id', type: 'integer', nullable: true),
            new OA\Property(property: 'metric', type: 'string', enum: ['diamonds_earned', 'coins_spent']),
            new OA\Property(property: 'min_value', type: 'integer', minimum: 0),
            new OA\Property(property: 'max_value', type: 'integer', description: 'null means "and above"', nullable: true),
            new OA\Property(property: 'percentage_bp', type: 'integer', maximum: 10000, minimum: 0, description: '1250 = 12.50%', example: 2500),
            new OA\Property(property: 'effective_from', type: 'string', format: 'date-time', nullable: true),
        ]
    )),
    responses: [
        new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` with `details.overlapping` listing the colliding slabs', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Delete(
    path: '/admin/economy/commission-slabs/{slab}',
    summary: 'Close a commission slab',
    description: 'Sets `effective_to` rather than deleting: past settlements were computed with it and the history has to stay explicable.',
    security: [['bearerAuth' => []]],
    tags: ['Economy'],
    parameters: [new OA\Parameter(name: 'slab', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'Closed', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/economy/ledger',
    summary: 'The unified transaction ledger (GFT-072)',
    description: 'Both currencies, one screen. Rows are immutable, so a correction appears as a new compensating entry rather than an edit.',
    security: [['bearerAuth' => []]],
    tags: ['Economy'],
    parameters: [
        new OA\Parameter(name: 'currency', in: 'query', schema: new OA\Schema(type: 'string', enum: ['coin', 'diamond'], default: 'coin')),
        new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string'), example: 'admin_credit'),
        new OA\Parameter(name: 'user_id', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1)),
    ],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/economy/reconciliation',
    summary: 'Ledger-vs-wallet reconciliation (A.7d)',
    description: <<<'MD'
docs/02 §15 rule 4: for every user, the signed sum of their ledger movements must equal
their wallet balance. A mismatch means a balance moved without a ledger row beside it —
the one thing the money rules exist to prevent.

A discrepancy **names the user and the exact delta**, because a count is not actionable.
Runs as one grouped query per currency, not a per-user loop.

The nightly `economy:reconcile` command runs the same engine and exits non-zero on drift.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Economy'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'The report — `ok: false` still returns 200; it is a finding, not a failure',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'ok', type: 'boolean'),
                    new OA\Property(property: 'checked_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'currencies', type: 'object'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `economy.reconcile`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/economy/reconciliation/run',
    summary: 'Run reconciliation and record it',
    description: 'Same check as the GET, but written to the audit log as a deliberate act.',
    security: [['bearerAuth' => []]],
    tags: ['Economy'],
    responses: [new OA\Response(response: 200, description: 'The report', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]

// --------------------------------------------------------------- withdrawals

#[OA\Get(
    path: '/admin/withdrawals',
    summary: 'The payout queue (A.7b)',
    description: 'Ordered **oldest first** — a payout queue is a queue, and the person waiting longest should be dealt with first.',
    security: [['bearerAuth' => []]],
    tags: ['Payouts'],
    parameters: [
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'pending_super_approval', 'approved', 'rejected', 'paid'])),
        new OA\Parameter(name: 'user_id', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WithdrawalRow')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `payouts.view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/withdrawals/summary',
    summary: 'Queue totals',
    description: 'What is waiting and what it is worth, plus the current policy thresholds.',
    security: [['bearerAuth' => []]],
    tags: ['Payouts'],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/withdrawals/{withdrawal}',
    summary: 'One payout request, with the payee’s KYC',
    description: 'KYC is included because it is the gate on being paid at all (A.3b) — reviewing a payout without it means opening two screens.',
    security: [['bearerAuth' => []]],
    tags: ['Payouts'],
    parameters: [new OA\Parameter(name: 'withdrawal', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/withdrawals/{withdrawal}/approve',
    summary: 'Approve a payout (A.7b)',
    description: <<<'MD'
Converts the frozen diamonds into a paid ledger entry — docs/02 §15 rule 10's "freeze,
then pay". The wallet row is locked `FOR UPDATE`, and the balance change plus its ledger
row happen in one transaction.

**Above the configured threshold this does not pay.** It moves the request to
`pending_super_approval` and returns `needs_super_admin: true`; only a Super Admin can
then clear it (GFT-070, and the SLA's second sign-off on large payouts). The first
approver is remembered on the row.

Approve and reject are mutually exclusive — whichever lands first wins, and the other
returns 400.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Payouts'],
    parameters: [new OA\Parameter(name: 'withdrawal', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'Approved, or escalated for a second approval', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — already decided', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 403, description: '`FORBIDDEN` — above the threshold and you are not a Super Admin · or `PERMISSION_DENIED` without `payouts.approve`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/withdrawals/{withdrawal}/reject',
    summary: 'Reject a payout and return the diamonds',
    description: 'Unfreezes **exactly** the amount that was frozen. No ledger row is written, because nothing moved — the diamonds simply become spendable again. Behind `payouts.reject`, separate from approve: being trusted to pay someone is not the same as being trusted to refuse them.',
    security: [['bearerAuth' => []]],
    tags: ['Payouts'],
    parameters: [new OA\Parameter(name: 'withdrawal', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
        new OA\Property(property: 'reason', type: 'string', maxLength: 500, example: 'KYC has not been verified.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Rejected', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — already decided', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — no reason given', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Patch(
    path: '/admin/withdrawal-settings',
    summary: 'Withdrawal policy thresholds',
    description: 'The minimum a user may withdraw and the value above which a second Super Admin approval is required. ⚠ CI-03 supplies the real policy; these are configurable so no code changes when it lands.',
    security: [['bearerAuth' => []]],
    tags: ['Payouts'],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'minimum_diamonds', type: 'integer', minimum: 0, example: 1000),
        new OA\Property(property: 'super_approval_paise', type: 'integer', description: '5000000 = Rs 50,000', example: 5000000),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `settings.manage`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
class EconomyPaths
{
}
