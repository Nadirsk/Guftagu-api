<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/** OpenAPI operations for epic A.8 — AgencyController, HostController, SettlementController. */
#[OA\Get(
    path: '/admin/agencies',
    summary: 'List agencies (A.8a)',
    description: 'Pending first, then approved, suspended, rejected — an application waiting on a human is what this screen exists to clear. `host_count` counts approved hosts only.',
    security: [['bearerAuth' => []]],
    tags: ['Agencies'],
    parameters: [
        new OA\Parameter(name: 'q', in: 'query', description: 'Name or code. Contact details are ciphertext, so a phone or email must be given in full to match.', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'suspended', 'rejected'])),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AgencyRow')),
            new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
        ])),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `agency.view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/agencies/{agency}',
    summary: 'One agency with its performance for a period',
    description: 'Defaults to the current month. Contact details come back masked; `documents` lists what was uploaded for review.',
    security: [['bearerAuth' => []]],
    tags: ['Agencies'],
    parameters: [
        new OA\Parameter(name: 'agency', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
    ],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/agencies',
    summary: 'Create an agency',
    description: 'Created as `pending` with a sequential code (`AGY-0001`). Commission is integer basis points, never a float percentage.',
    security: [['bearerAuth' => []]],
    tags: ['Agencies'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['name'],
        properties: [
            new OA\Property(property: 'name', type: 'string', example: 'Mumbai Voice Collective'),
            new OA\Property(property: 'owner_user_id', type: 'integer', nullable: true),
            new OA\Property(property: 'contact_phone', type: 'string', nullable: true),
            new OA\Property(property: 'contact_email', type: 'string', nullable: true),
            new OA\Property(property: 'commission_bp', type: 'integer', maximum: 10000, minimum: 0, example: 1500),
            new OA\Property(property: 'managed_by', type: 'integer', nullable: true),
        ]
    )),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Patch(
    path: '/admin/agencies/{agency}',
    summary: 'Edit an agency',
    security: [['bearerAuth' => []]],
    tags: ['Agencies'],
    parameters: [new OA\Parameter(name: 'agency', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AgencyRow')),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/agencies/{agency}/documents',
    summary: 'Record an uploaded document',
    security: [['bearerAuth' => []]],
    tags: ['Agencies'],
    parameters: [new OA\Parameter(name: 'agency', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'type', type: 'string', example: 'gst'),
        new OA\Property(property: 'url', type: 'string', format: 'uri'),
    ])),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/agencies/{agency}/approve',
    summary: 'Approve an agency (A.8a)',
    description: <<<'MD'
Approval is what makes an agency selectable by host applicants, so it is gated on there
being something to have reviewed: an agency with **no documents on file** is refused with
`DOCUMENTS_MISSING` rather than recording a review that never happened.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Agencies'],
    parameters: [new OA\Parameter(name: 'agency', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 422, description: '`DOCUMENTS_MISSING`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/agencies/{agency}/reject',
    summary: 'Reject an agency',
    security: [['bearerAuth' => []]],
    tags: ['Agencies'],
    parameters: [new OA\Parameter(name: 'agency', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'reason', type: 'string', description: 'The applicant is told this.'),
    ])),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/agencies/{agency}/suspend',
    summary: 'Suspend an agency',
    description: '**Does not cascade to its hosts.** Their contracts and earnings are their own; cutting a host off because their agency is under review punishes the wrong person. The response says so with `hosts_affected: false`. The agency stops being settled and stops accepting new hosts.',
    security: [['bearerAuth' => []]],
    tags: ['Agencies'],
    parameters: [new OA\Parameter(name: 'agency', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'reason', type: 'string'),
    ])),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/agencies/{agency}/reinstate',
    summary: 'Lift a suspension',
    security: [['bearerAuth' => []]],
    tags: ['Agencies'],
    parameters: [new OA\Parameter(name: 'agency', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/host-applications',
    summary: 'The host approval queue (A.8a)',
    description: 'Oldest first, pending by default. `intro_audio_url` is the clip the reviewer listens to before deciding.',
    security: [['bearerAuth' => []]],
    tags: ['Hosts'],
    parameters: [
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected'])),
        new OA\Parameter(name: 'agency_id', in: 'query', schema: new OA\Schema(type: 'integer')),
    ],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/host-applications/{application}/approve',
    summary: 'Approve a host application',
    description: 'Creates the host record, or **reactivates an existing one** — somebody who left and came back keeps the same row, because a second row would split their earnings history in two. Assigning to an agency that is not approved is refused with `AGENCY_NOT_APPROVED`.',
    security: [['bearerAuth' => []]],
    tags: ['Hosts'],
    parameters: [new OA\Parameter(name: 'application', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'agency_id', type: 'integer', nullable: true, description: 'Overrides the agency the applicant chose.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 422, description: '`AGENCY_NOT_APPROVED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/host-applications/{application}/reject',
    summary: 'Reject a host application',
    security: [['bearerAuth' => []]],
    tags: ['Hosts'],
    parameters: [new OA\Parameter(name: 'application', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'reason', type: 'string'),
    ])),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/hosts',
    summary: 'List hosts',
    description: '`under_contract` is derived from the contract dates at read time, so a contract that ended yesterday reads correctly today with no job having run. `status` is untouched by that.',
    security: [['bearerAuth' => []]],
    tags: ['Hosts'],
    parameters: [
        new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'suspended', 'rejected', 'left'])),
        new OA\Parameter(name: 'agency_id', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'unassigned', in: 'query', schema: new OA\Schema(type: 'boolean')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HostRow')),
            new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
        ])),
    ]
)]
#[OA\Get(
    path: '/admin/hosts/{host}',
    summary: 'One host with their daily earnings (A.8c)',
    description: <<<'MD'
`daily` is the `host_earnings` rollup, which is a pure function of the diamond ledger and
is recomputed rather than incremented — running the nightly job twice produces the same
numbers, and a late credit corrects the day instead of adding to it.

Two figures are deliberately not invented:

- **`unique_gifters` is null, not zero.** The diamond ledger does not record who sent a
  gift, so it stays uncountable until `gift_transactions` lands with D.1. Zero would read
  as "nobody gifted them".
- **`room_hours` is zero** until room session tracking lands with D.3.

`pricing_note` is set when diamonds were earned but no `diamond_to_inr` rate covered the
period — a zero rupee figure against real diamonds is a missing rate, not a quiet month.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Hosts'],
    parameters: [
        new OA\Parameter(name: 'host', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
    ],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/hosts/{host}/earnings/verify',
    summary: 'Prove the rollup still equals the ledger (A.8c)',
    description: 'Re-derives the range straight from `diamond_transactions` and reports the difference, the same way the wallet integrity check does. A mismatch is fixed with `php artisan hosts:rollup-earnings --from --to`.',
    security: [['bearerAuth' => []]],
    tags: ['Hosts'],
    parameters: [
        new OA\Parameter(name: 'host', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'matches', type: 'boolean'),
                new OA\Property(property: 'rollup_diamonds', type: 'integer'),
                new OA\Property(property: 'ledger_diamonds', type: 'integer'),
                new OA\Property(property: 'difference', type: 'integer'),
            ], type: 'object'),
        ])),
    ]
)]
#[OA\Patch(
    path: '/admin/hosts/{host}',
    summary: 'Edit a host contract',
    security: [['bearerAuth' => []]],
    tags: ['Hosts'],
    parameters: [new OA\Parameter(name: 'host', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'tier', type: 'string', nullable: true),
        new OA\Property(property: 'base_commission_bp', type: 'integer', maximum: 10000, minimum: 0),
        new OA\Property(property: 'contract_start', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'contract_end', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ])),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/hosts/{host}/agency',
    summary: 'Move a host between agencies',
    description: 'The old membership is **closed, not edited** — which agency a host belonged to during a period is what a settlement is priced from, so overwriting it would silently re-price the past. Earnings already rolled up stay attributed to the previous agency. Pass `agency_id: null` to unassign.',
    security: [['bearerAuth' => []]],
    tags: ['Hosts'],
    parameters: [new OA\Parameter(name: 'host', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'agency_id', type: 'integer', nullable: true),
    ])),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/hosts/{host}/status',
    summary: 'Change a host status',
    security: [['bearerAuth' => []]],
    tags: ['Hosts'],
    parameters: [new OA\Parameter(name: 'host', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'approved', 'suspended', 'rejected', 'left']),
        new OA\Property(property: 'note', type: 'string', nullable: true),
    ])),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/hosts/targets',
    summary: 'Host targets across the platform (A.8b)',
    security: [['bearerAuth' => []]],
    tags: ['Hosts'],
    parameters: [
        new OA\Parameter(name: 'host_id', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'agency_id', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'achieved', 'missed', 'cancelled'])),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HostTargetRow')),
            new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
        ])),
    ]
)]
#[OA\Get(
    path: '/admin/hosts/targets/{target}',
    summary: 'One target',
    description: '`source` says where the figures came from: `derived live from host_earnings` while the period is open, `frozen at evaluation` once it has closed.',
    security: [['bearerAuth' => []]],
    tags: ['Hosts'],
    parameters: [new OA\Parameter(name: 'target', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/hosts/{host}/targets',
    summary: 'Set a target for a period (A.8b)',
    description: <<<'MD'
Achievement is measured only on the metrics that were actually set. A target of 100,000
diamonds and no hours target is 100% about diamonds — averaging in a zero for an unset
metric would report 50% for a host who hit their number exactly.

Overlapping periods are refused with `PERIOD_OVERLAP`, because the same diamonds counting
towards two incentives makes achievement ambiguous.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Hosts'],
    parameters: [new OA\Parameter(name: 'host', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['period_start', 'period_end'],
        properties: [
            new OA\Property(property: 'period_start', type: 'string', format: 'date'),
            new OA\Property(property: 'period_end', type: 'string', format: 'date'),
            new OA\Property(property: 'target_diamonds', type: 'integer', example: 100000),
            new OA\Property(property: 'target_hours', type: 'integer', description: 'Cannot be met until room session tracking lands with D.3.'),
            new OA\Property(property: 'target_days', type: 'integer', example: 20),
        ]
    )),
    responses: [
        new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 422, description: '`PERIOD_OVERLAP`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/hosts/targets/{target}/evaluate',
    summary: 'Freeze a target and compute its incentive',
    description: 'Normally the nightly `hosts:evaluate-targets` job does this. The incentive slab is looked up **on achievement percentage, at the period end date**, so editing the slab table next month does not repay last month. Once frozen the figures never move again, even if a late credit lands.',
    security: [['bearerAuth' => []]],
    tags: ['Hosts'],
    parameters: [new OA\Parameter(name: 'target', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — already evaluated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Delete(
    path: '/admin/hosts/targets/{target}',
    summary: 'Cancel a target',
    description: 'Refused once the target has been evaluated — an incentive was computed from it.',
    security: [['bearerAuth' => []]],
    tags: ['Hosts'],
    parameters: [new OA\Parameter(name: 'target', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/settlements',
    summary: 'Agency settlements (A.8d)',
    security: [['bearerAuth' => []]],
    tags: ['Settlements'],
    parameters: [
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['draft', 'manager_raised', 'admin_approved', 'paid', 'rejected'])),
        new OA\Parameter(name: 'agency_id', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'period', in: 'query', description: 'Any date inside the month.', schema: new OA\Schema(type: 'string', format: 'date')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SettlementRow')),
            new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
        ])),
    ]
)]
#[OA\Get(
    path: '/admin/settlements/batches',
    summary: 'Settlement payout batches',
    security: [['bearerAuth' => []]],
    tags: ['Settlements'],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/settlements/{settlement}',
    summary: 'One settlement with its batch',
    security: [['bearerAuth' => []]],
    tags: ['Settlements'],
    parameters: [new OA\Parameter(name: 'settlement', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/settlements/generate',
    summary: 'Build or rebuild a period draft (A.8d)',
    description: <<<'MD'
Idempotent, and it replaces **only a draft**. Running it twice for the same period updates
the one row; a settlement somebody has already raised or approved comes back `ALREADY_RAISED`
(409) rather than being silently rewritten underneath them.

**The splits add back to gross, exactly.** The platform share is derived as
`gross − agency − host` rather than re-applying a rate, which is what guarantees the
identity holds; the write is refused outright if it does not.

The conversion rate is frozen onto the row, so approving next week still settles at the
price that applied during the period — the same rule withdrawals follow.

`net_payable_paise` is the agency commission only. Host earnings are paid to hosts, not
through the agency.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Settlements'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['agency_id', 'period_start', 'period_end'],
        properties: [
            new OA\Property(property: 'agency_id', type: 'integer'),
            new OA\Property(property: 'period_start', type: 'string', format: 'date'),
            new OA\Property(property: 'period_end', type: 'string', format: 'date'),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 409, description: '`ALREADY_RAISED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`AGENCY_NOT_APPROVED` or `SPLIT_IMBALANCE`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/settlements/{settlement}/raise',
    summary: 'Raise a draft for approval',
    description: 'Needs `agency.settlement_raise`, which a Manager holds. Approving needs `agency.settlement_process`, which they do not.',
    security: [['bearerAuth' => []]],
    tags: ['Settlements'],
    parameters: [new OA\Parameter(name: 'settlement', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ])),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/settlements/{settlement}/approve',
    summary: 'Approve a settlement',
    description: 'Two-person rule: whoever raised it cannot approve it, and gets `SELF_APPROVAL` (403).',
    security: [['bearerAuth' => []]],
    tags: ['Settlements'],
    parameters: [new OA\Parameter(name: 'settlement', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`SELF_APPROVAL`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/settlements/{settlement}/reject',
    summary: 'Reject a settlement',
    security: [['bearerAuth' => []]],
    tags: ['Settlements'],
    parameters: [new OA\Parameter(name: 'settlement', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'reason', type: 'string'),
    ])),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/settlements/batch',
    summary: 'Put approved settlements into a payout batch',
    description: 'Only `admin_approved` settlements may be batched (`NOT_APPROVED`), and one already in a batch is refused with `ALREADY_BATCHED` (409). The batch total is computed from its members.',
    security: [['bearerAuth' => []]],
    tags: ['Settlements'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'settlement_ids', type: 'array', items: new OA\Items(type: 'integer')),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 409, description: '`ALREADY_BATCHED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/settlements/batches/{batch}/process',
    summary: 'Pay a batch (A.8d)',
    description: <<<'MD'
**Idempotent.** Only settlements not already `paid` are touched, and the batch total is
recomputed from its members rather than accumulated — a total that is incremented drifts
the moment anything is retried, which is the worst possible bug here.

Running it a second time returns `newly_paid: 0`, `already_paid: n`, an unchanged
`total_paise`, and a note saying so.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Settlements'],
    parameters: [new OA\Parameter(name: 'batch', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'batch_number', type: 'string'),
                new OA\Property(property: 'newly_paid', type: 'integer'),
                new OA\Property(property: 'already_paid', type: 'integer'),
                new OA\Property(property: 'count', type: 'integer'),
                new OA\Property(property: 'total_paise', type: 'integer'),
            ], type: 'object'),
        ])),
    ]
)]
#[OA\Schema(
    schema: 'AgencyRow',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'code', type: 'string', example: 'AGY-0001'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'commission_bp', type: 'integer', description: 'Integer basis points. 1500 = 15%.'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'approved', 'suspended', 'rejected']),
        new OA\Property(property: 'is_approved', type: 'boolean'),
        new OA\Property(property: 'host_count', type: 'integer', description: 'Approved hosts only.'),
        new OA\Property(property: 'document_count', type: 'integer'),
        new OA\Property(property: 'approved_by', type: 'string', nullable: true),
        new OA\Property(property: 'managed_by', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'HostRow',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'guftagu_id', type: 'string', nullable: true),
        new OA\Property(property: 'display_name', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'tier', type: 'string', nullable: true),
        new OA\Property(property: 'base_commission_bp', type: 'integer'),
        new OA\Property(property: 'under_contract', type: 'boolean', description: 'Derived from the contract dates, not a stored flag.'),
    ]
)]
#[OA\Schema(
    schema: 'HostTargetRow',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'host_id', type: 'integer'),
        new OA\Property(property: 'period_start', type: 'string', format: 'date'),
        new OA\Property(property: 'period_end', type: 'string', format: 'date'),
        new OA\Property(property: 'target_diamonds', type: 'integer'),
        new OA\Property(property: 'achieved_diamonds', type: 'integer', nullable: true),
        new OA\Property(property: 'achievement_pct', type: 'integer', nullable: true, description: 'Null when no metric was set — nobody can hit or miss that.'),
        new OA\Property(property: 'incentive_paise', type: 'integer', nullable: true),
        new OA\Property(property: 'incentive_bp', type: 'integer', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'achieved', 'missed', 'cancelled']),
        new OA\Property(property: 'is_frozen', type: 'boolean'),
        new OA\Property(property: 'source', type: 'string', enum: ['derived live from host_earnings', 'frozen at evaluation']),
    ]
)]
#[OA\Schema(
    schema: 'SettlementRow',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'period_start', type: 'string', format: 'date'),
        new OA\Property(property: 'period_end', type: 'string', format: 'date'),
        new OA\Property(property: 'gross_diamonds', type: 'integer'),
        new OA\Property(property: 'gross_paise', type: 'integer'),
        new OA\Property(property: 'platform_cut_paise', type: 'integer'),
        new OA\Property(property: 'agency_cut_paise', type: 'integer'),
        new OA\Property(property: 'host_cut_paise', type: 'integer'),
        new OA\Property(property: 'net_payable_paise', type: 'integer', description: 'The agency commission — what the platform actually transfers.'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'splits_balance', type: 'boolean', description: 'platform + agency + host == gross. Asserted at write, reported at read.'),
        new OA\Property(property: 'batch_id', type: 'integer', nullable: true),
    ]
)]
class AgencyPaths
{
}
