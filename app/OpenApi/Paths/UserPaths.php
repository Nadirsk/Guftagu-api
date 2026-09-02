<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operations for epic A.3 — UserController and UserWalletController.
 *
 * Keep in sync with those controllers by hand; OpenApiDocumentTest fails the build if a
 * route here stops matching a real one, or a real one gains no docs.
 */
#[OA\Get(
    path: '/admin/users',
    summary: 'List app users (GFT-023)',
    description: <<<'MD'
Offset pagination per docs/03 §2.3.

**Searching by phone is an exact match, not a prefix.** `phone` is encrypted at rest, so
there is nothing to `LIKE` against — the query hashes your term and looks up `phone_hash`.
A partial number therefore finds nothing while a full one is instant. Both `9876543210`
and `+919876543210` work; `guftagu_id` and display name still match on substring.

Phone and email come back **masked**. The unmasked value has its own endpoint and its own
permission, so seeing it is always recorded.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Users'],
    parameters: [
        new OA\Parameter(name: 'q', description: 'Full phone, guftagu_id, or part of a display name', in: 'query', schema: new OA\Schema(type: 'string'), example: '9820011221'),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'suspended', 'banned', 'deleted'])),
        new OA\Parameter(name: 'kyc', description: '`none` matches users who never submitted', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'verified', 'rejected', 'none'])),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1)),
        new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['id', 'guftagu_id', 'status', 'created_at', 'last_active_at', '-id', '-guftagu_id', '-status', '-created_at', '-last_active_at']), example: '-created_at'),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/UserRow')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `users.view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/users/{user}',
    summary: 'User detail aggregate (GFT-024)',
    description: 'Profile, wallet, KYC, recent devices and sanction history in one call. `pending` names the sections that arrive with later modules (rooms with A.4, reports with A.5) so the panel can say so rather than render an empty list that looks like no data.',
    security: [['bearerAuth' => []]],
    tags: ['Users'],
    parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 1)],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/UserRow'),
                    new OA\Property(property: 'profile', type: 'object', nullable: true),
                    new OA\Property(property: 'wallet', ref: '#/components/schemas/WalletSummary'),
                    new OA\Property(property: 'kyc', type: 'object', nullable: true, description: 'Document number is masked to its last four'),
                    new OA\Property(property: 'devices', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'sanctions', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'pending', type: 'object', description: 'Sections not built yet'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/users/{user}/pii',
    summary: 'Reveal the unmasked phone and email (GFT-025)',
    description: '**Every call writes a `user.pii_viewed` audit row** naming the viewer and the subject. Masking everywhere else is only meaningful if the way around it leaves a trail — which is why this is a separate endpoint behind a separate `high` risk permission rather than a flag on the list.',
    security: [['bearerAuth' => []]],
    tags: ['Users'],
    parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Recorded and returned',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'This access has been recorded'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'phone', type: 'string', example: '+919820011221'),
                    new OA\Property(property: 'email', type: 'string', nullable: true),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `users.view_pii`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Patch(
    path: '/admin/users/{user}',
    summary: 'Edit a user profile',
    security: [['bearerAuth' => []]],
    tags: ['Users'],
    parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'display_name', type: 'string', maxLength: 50),
        new OA\Property(property: 'bio', type: 'string', maxLength: 300, nullable: true),
        new OA\Property(property: 'country', type: 'string', nullable: true),
        new OA\Property(property: 'city', type: 'string', nullable: true),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'User updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `users.edit`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/users/{user}/suspend',
    summary: 'Suspend a user (A.3c)',
    description: 'A reason is mandatory — there are no silent sanctions. Omit `until` for an open-ended suspension. Supersedes any sanction already in force and **ends the user’s live sessions immediately**.',
    security: [['bearerAuth' => []]],
    tags: ['Users'],
    parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
        new OA\Property(property: 'reason', type: 'string', maxLength: 500, example: 'Spam links in room chat.'),
        new OA\Property(property: 'until', type: 'string', format: 'date-time', description: 'Must be in the future', nullable: true),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'User suspended', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `users.suspend`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — missing reason, or a past date', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/users/{user}/ban',
    summary: 'Ban a user permanently (A.3c)',
    description: 'Reason mandatory. Sets status to `banned`, writes a `permanent_ban` sanction, and deletes every token the user holds so the ban bites at once rather than at next login.',
    security: [['bearerAuth' => []]],
    tags: ['Users'],
    parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
        new OA\Property(property: 'reason', type: 'string', maxLength: 500, example: 'Repeated harassment after two warnings.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'User banned', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `users.ban`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/users/{user}/unban',
    summary: 'Reinstate a suspended or banned user',
    security: [['bearerAuth' => []]],
    tags: ['Users'],
    parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
        new OA\Property(property: 'reason', type: 'string', maxLength: 500, example: 'Appeal upheld.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'User reinstated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — the account is already active', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/users/{user}/kyc/verify',
    summary: 'Approve or reject a KYC submission (A.3b)',
    description: 'Approving makes the user eligible to withdraw. A rejection needs a reason. A submission can only be decided **once** — re-reviewing a decided one returns 400 rather than silently overwriting the first decision.',
    security: [['bearerAuth' => []]],
    tags: ['Users'],
    parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['decision'], properties: [
        new OA\Property(property: 'decision', type: 'string', enum: ['verified', 'rejected']),
        new OA\Property(property: 'reason', type: 'string', description: 'Required when rejecting', nullable: true),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Reviewed', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — already reviewed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND` — no submission', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — rejecting without a reason', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]

// -------------------------------------------------------------------- wallet

#[OA\Get(
    path: '/admin/users/{user}/wallet',
    summary: 'Wallet balances',
    description: 'All values are integer counts — coins and diamonds are never floats (docs/02 §15 rule 1). `available_*` excludes anything frozen against a pending operation.',
    security: [['bearerAuth' => []]],
    tags: ['Wallet'],
    parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/WalletSummary'),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `wallet.view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/users/{user}/transactions',
    summary: 'The ledger for one currency',
    description: 'Newest first. Rows are immutable — a correction appears as a new compensating entry, never as an edit.',
    security: [['bearerAuth' => []]],
    tags: ['Wallet'],
    parameters: [
        new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'currency', in: 'query', schema: new OA\Schema(type: 'string', enum: ['coin', 'diamond'], default: 'coin')),
        new OA\Parameter(name: 'type', description: 'e.g. admin_credit, recharge, gift_received', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LedgerRow')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `wallet.ledger_view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/users/{user}/wallet/credit',
    summary: 'Manually credit a wallet (A.3d)',
    description: <<<'MD'
Every rule in docs/02 §15 applies:

- **A note is mandatory.** Money that moves without a stated reason is money nobody can
  explain later — omitting it returns 422 and writes nothing.
- The wallet row is locked `FOR UPDATE` before its balance is read.
- The balance change and the ledger row are written in **one transaction**.
- The row records `balance_before`, `balance_after` and `performed_by`, and an
  `audit_logs` entry is written alongside it.
- Amounts are **integers only** — `10.5` is rejected.

Send `X-Idempotency-Key` to make a retry safe: a replay returns the original row instead of
moving money twice.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Wallet'],
    parameters: [
        new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'X-Idempotency-Key', description: 'Optional. Makes a retry return the original result.', in: 'header', schema: new OA\Schema(type: 'string', maxLength: 64)),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['currency', 'amount', 'note'], properties: [
        new OA\Property(property: 'currency', type: 'string', enum: ['coin', 'diamond']),
        new OA\Property(property: 'amount', type: 'integer', maximum: 1000000000, minimum: 1, example: 1000),
        new OA\Property(property: 'note', type: 'string', maxLength: 255, minLength: 3, example: 'Goodwill credit for the 12 Aug outage.'),
    ])),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Credited',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Credited 1,000 coins'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'ledger_uuid', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'balance_before', type: 'integer', example: 0),
                    new OA\Property(property: 'balance_after', type: 'integer', example: 1000),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `wallet.manual_credit`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — no note, or a fractional amount', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/users/{user}/wallet/debit',
    summary: 'Manually debit a wallet (A.3d)',
    description: 'Same rules as credit, behind a **separate permission**: being trusted to hand a user coins is not the same as being trusted to take them away. A debit larger than the balance is refused with `INSUFFICIENT_BALANCE` rather than driving the balance negative.',
    security: [['bearerAuth' => []]],
    tags: ['Wallet'],
    parameters: [
        new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'X-Idempotency-Key', in: 'header', schema: new OA\Schema(type: 'string', maxLength: 64)),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['currency', 'amount', 'note'], properties: [
        new OA\Property(property: 'currency', type: 'string', enum: ['coin', 'diamond']),
        new OA\Property(property: 'amount', type: 'integer', minimum: 1, example: 250),
        new OA\Property(property: 'note', type: 'string', maxLength: 255, minLength: 3, example: 'Reversing a duplicated recharge.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Debited', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `wallet.manual_debit`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`INSUFFICIENT_BALANCE` (with `details.available`) or `VALIDATION_ERROR`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/users/{user}/wallet/freeze',
    summary: 'Freeze or unfreeze a wallet (GFT-030)',
    description: 'A freeze blocks the user, not the admin — manual corrections still work on a frozen wallet, which is usually why it was frozen.',
    security: [['bearerAuth' => []]],
    tags: ['Wallet'],
    parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['frozen', 'reason'], properties: [
        new OA\Property(property: 'frozen', type: 'boolean'),
        new OA\Property(property: 'reason', type: 'string', maxLength: 500, example: 'Under investigation for chargeback fraud.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `wallet.manual_debit`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/users/{user}/wallet/integrity',
    summary: 'Prove the ledger still reconciles',
    description: 'docs/02 §15 rule 4 as a runnable check: walks the ledger and verifies each row’s `balance_before` equals the previous `balance_after`, and that the last one equals the wallet. The nightly reconciliation job is the real safety net — this is for when someone is staring at a balance they do not believe.',
    security: [['bearerAuth' => []]],
    tags: ['Wallet'],
    parameters: [
        new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'currency', in: 'query', schema: new OA\Schema(type: 'string', enum: ['coin', 'diamond'], default: 'coin')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Checked',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'checked', type: 'integer', example: 4),
                    new OA\Property(property: 'wallet_balance', type: 'integer', example: 925),
                    new OA\Property(property: 'ledger_balance', type: 'integer', example: 925),
                    new OA\Property(property: 'breaks', type: 'array', items: new OA\Items(type: 'object')),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
    ]
)]
class UserPaths
{
}
