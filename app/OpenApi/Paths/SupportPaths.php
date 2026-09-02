<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/** OpenAPI operations for epic B.4 and the FR.B/FR.C additions. */
#[OA\Get(
    path: '/admin/support',
    summary: 'The support inbox (B.4)',
    description: <<<'MD'
Urgent first, then oldest — the same reasoning as the reports queue.

`sla_state` is one word for the whole SLA position: `on_track`, `at_risk`,
`response_breached`, `resolution_breached` or `closed`. It is **derived from the clock**, so
a ticket crosses into breach without anything having to run — a stored flag would be stale
between job runs and a stalled scheduler would hide every breach.

Each ticket is measured against **the promise that applied when it was raised**, copied onto
the row at creation. Tightening the policy next month must not retroactively put last
month's tickets in breach.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Support'],
    parameters: [
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['open', 'pending', 'resolved', 'closed'])),
        new OA\Parameter(name: 'priority', in: 'query', schema: new OA\Schema(type: 'string', enum: ['low', 'medium', 'high', 'urgent'])),
        new OA\Parameter(name: 'sla', in: 'query', description: 'The filters a support lead actually uses.', schema: new OA\Schema(type: 'string', enum: ['breaching', 'unanswered', 'escalated'])),
        new OA\Parameter(name: 'mine', in: 'query', schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'q', in: 'query', description: 'Ticket ref or subject.', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SupportTicketRow')),
            new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
        ])),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `support.view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/support/summary',
    summary: 'Inbox totals',
    security: [['bearerAuth' => []]],
    tags: ['Support'],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/support/breaching',
    summary: 'Tickets past their first-response promise',
    description: 'Past the promise and still unanswered. Each is measured against its own stored SLA, computed in SQL.',
    security: [['bearerAuth' => []]],
    tags: ['Support'],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/support/canned-replies',
    summary: 'Saved replies',
    description: 'Ordered by how often each has been used — which replies actually get used is the only honest guide to which are worth keeping.',
    security: [['bearerAuth' => []]],
    tags: ['Support'],
    parameters: [new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/support/canned-replies',
    summary: 'Save a reply',
    security: [['bearerAuth' => []]],
    tags: ['Support'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['title', 'body_en'],
        properties: [
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'category', type: 'string'),
            new OA\Property(property: 'body_en', type: 'string'),
            new OA\Property(property: 'body_hi', type: 'string', nullable: true),
        ]
    )),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Delete(
    path: '/admin/support/canned-replies/{cannedReply}',
    summary: 'Remove a saved reply',
    security: [['bearerAuth' => []]],
    tags: ['Support'],
    parameters: [new OA\Parameter(name: 'cannedReply', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/support/flag-room',
    summary: 'Flag a room to moderation (B.4b)',
    description: <<<'MD'
Writes a **`high` priority report naming the flagging Manager**, into the same `reports`
table the Moderator console reads. B.4b asks that it appear in the Moderator queue "within
5 seconds"; there is nothing to wait for, because there is no sync step.

The priority is fixed rather than chosen. A Manager flagging a live room is not routine, and
letting them pick would put some of these at the bottom of the queue.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Support'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['room_id', 'reason'],
        properties: [
            new OA\Property(property: 'room_id', type: 'integer'),
            new OA\Property(property: 'reason', type: 'string'),
        ]
    )),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/support',
    summary: 'Open a ticket on somebody\'s behalf',
    description: 'The SLA minutes are copied onto the row from the priority, not read back from settings later.',
    security: [['bearerAuth' => []]],
    tags: ['Support'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['subject', 'description'],
        properties: [
            new OA\Property(property: 'user_id', type: 'integer', nullable: true),
            new OA\Property(property: 'category', type: 'string', enum: ['payment', 'account', 'room', 'harassment', 'kyc', 'withdrawal', 'bug', 'other']),
            new OA\Property(property: 'subject', type: 'string'),
            new OA\Property(property: 'description', type: 'string'),
            new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'urgent']),
        ]
    )),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/support/{ticket}',
    summary: 'One ticket, as a conversation',
    description: 'The opening description is the first message in the thread, so the view has one shape rather than "description, then messages". `is_internal` marks a staff-only note — one rendered like a reply is how a private remark ends up quoted back at the customer.',
    security: [['bearerAuth' => []]],
    tags: ['Support'],
    parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/support/{ticket}/assign',
    summary: 'Assign a ticket',
    security: [['bearerAuth' => []]],
    tags: ['Support'],
    parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'admin_user_id', type: 'integer'),
    ])),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/support/{ticket}/reply',
    summary: 'Reply, or leave an internal note (B.4a)',
    description: <<<'MD'
A reply notifies the person who raised the ticket and **stops the first-response timer**,
once — `first_response_at` is written only if it is still null, because "how long until
somebody answered" is a fact about the past.

**An internal note does not stop the clock.** The person waiting has not heard anything, so
the promise has not been kept; recording it as a response would make the SLA report
flattering and useless. The response says so explicitly.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Support'],
    parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['body'],
        properties: [
            new OA\Property(property: 'body', type: 'string'),
            new OA\Property(property: 'is_internal', type: 'boolean', description: 'Staff-only. Never shown to the person who raised the ticket, and does not stop the timer.'),
            new OA\Property(property: 'canned_reply_id', type: 'integer', nullable: true),
        ]
    )),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/support/{ticket}/resolve',
    summary: 'Resolve a ticket',
    security: [['bearerAuth' => []]],
    tags: ['Support'],
    parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'resolution', type: 'string'),
    ])),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/support/{ticket}/escalate',
    summary: 'Escalate to an Admin (B.4c)',
    description: 'Both halves: the named Admin gets an in-panel notification **and** the escalation is recorded on the ticket and in its thread. A notification with no record is unauditable; a record with no notification is a message nobody reads. Escalating also reassigns the ticket — leaving it with whoever gave up on it is how a ticket ends up owned by nobody.',
    security: [['bearerAuth' => []]],
    tags: ['Support'],
    parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['admin_user_id', 'note'],
        properties: [
            new OA\Property(property: 'admin_user_id', type: 'integer'),
            new OA\Property(property: 'note', type: 'string', description: 'What is stuck.'),
        ]
    )),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/reports/{report}/claim',
    summary: 'Claim a report (C.3a)',
    description: <<<'MD'
> "A report claimed by one Moderator is not actionable by another until released."

A claim is **not** an assignment: `assigned_to` is a supervisor saying "you handle this", a
claim is a moderator saying "I am reading this now". It is what stops two people issuing two
bans off the same report.

Claims **expire on their own** after 20 minutes, derived from `claimed_at` at read time. A
moderator who claims a report and closes their laptop must not park a critical report
indefinitely, and a job that cleared stale claims would leave reports locked whenever it
stalled.

Claiming is taken under a row lock, so two moderators clicking at the same moment do not
both come away believing they hold it.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 409, description: '`ALREADY_CLAIMED` — names the holder and when it frees up', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Delete(
    path: '/admin/reports/{report}/claim',
    summary: 'Release a claim',
    description: 'Only the holder may release, so one moderator cannot bump another off a report they are mid-way through. A stale claim needs no release — it lapses on its own.',
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`NOT_YOURS`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/moderation/policy',
    summary: 'What this moderator is allowed to do (C.4b)',
    description: <<<'MD'
`max_ban_hours` is the ceiling on a temporary ban, read from the `max_ban_hours` key in the
moderator's grant scope. It exists so the duration picker can grey out what would be
refused rather than offering it and then failing.

Where two grants each carry a ceiling, **the tightest wins**: two people each set a limit
they expected to hold, and taking the looser one would let it silently override the
stricter. A Super Admin is uncapped.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/moderation/recurring',
    summary: 'Recurring issues (C.5c)',
    description: <<<'MD'
Users and rooms reported at or above the threshold inside a rolling window — five reports in
24 hours by default.

Derived on read. A `repeat_offender` flag written by a job would be wrong the moment the
window rolled forward, and the point of this panel is that it reflects the last 24 hours
right now.

`distinct_reporters` sits beside `reports` because the two say different things: five
reports from one person is a feud, five from five people is a pattern, and what a moderator
should do about it differs.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [
        new OA\Parameter(name: 'hours', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 168, minimum: 1, example: 24)),
        new OA\Parameter(name: 'threshold', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 2, example: 5)),
    ],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/moderation/my-actions',
    summary: 'A moderator\'s own actions (GFT-174)',
    description: 'Their own, **including the ones an Admin later reversed**. Hiding a reversal from the person who made the call would defeat the point of them having a log.',
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [new OA\Parameter(name: 'days', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 180, minimum: 1, example: 30))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/content/banners/{banner}/approve',
    summary: 'Approve a banner for the app (B.3b)',
    description: <<<'MD'
A banner nobody with `cms.banner_approve` has signed off is **never live**, however active
and in-window it is — its `state` reads `awaiting_approval`.

Whoever holds the approve permission self-approves on create; asking an Admin to click their
own banner live is ceremony. An **edit** by somebody who cannot approve sends the banner
back for approval, because otherwise a Manager could get a harmless banner signed off and
then swap the image and the link — the approval would be of something that no longer exists.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Content'],
    parameters: [new OA\Parameter(name: 'banner', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — already approved', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/rooms/feature-bulk',
    summary: 'Feature a set of rooms (B.3c)',
    description: 'All or none. A promotion that features three rooms and fails on the fourth leaves a half-run campaign somebody has to reconstruct by hand, so the whole set is validated — including category scope on every room — before anything is written. The featured window is derived at read time, so nothing has to un-feature them afterwards.',
    security: [['bearerAuth' => []]],
    tags: ['Rooms'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['room_ids', 'until'],
        properties: [
            new OA\Property(property: 'room_ids', type: 'array', items: new OA\Items(type: 'integer')),
            new OA\Property(property: 'until', type: 'string', format: 'date-time'),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`OUT_OF_SCOPE` — a room outside the caller\'s category scope', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/broadcasts/{broadcast}/outcome',
    summary: 'Campaign outcome (B.5b)',
    description: <<<'MD'
Reach, open rate and recharge activity after a send. Two of the three are real, and the
third is labelled for what it is:

- **reach** is `sent_count` — what was actually written.
- **open rate** needs receipts from the app, which do not exist yet (E.2c), so it comes back
  `null` rather than as 0%.
- **recharges** counts recharges by recipients within the window after the send.
  `attribution` is `correlated`, not causal: nobody clicked a tracked link, so some of it
  would have happened anyway. A number presented as causal here ends up in a client report
  as if it were.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Campaigns'],
    parameters: [
        new OA\Parameter(name: 'broadcast', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'window_hours', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 720, minimum: 1, example: 72)),
    ],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Schema(
    schema: 'SupportTicketRow',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'ref', type: 'string', example: 'TKT-000123'),
        new OA\Property(property: 'subject', type: 'string'),
        new OA\Property(property: 'category', type: 'string'),
        new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'urgent']),
        new OA\Property(property: 'status', type: 'string', enum: ['open', 'pending', 'resolved', 'closed']),
        new OA\Property(property: 'assigned_to', type: 'string', nullable: true),
        new OA\Property(property: 'escalated', type: 'boolean'),
        new OA\Property(property: 'sla_state', type: 'string', enum: ['on_track', 'at_risk', 'response_breached', 'resolution_breached', 'closed'], description: 'Derived from the clock, not a stored flag.'),
        new OA\Property(property: 'first_response_due_in', type: 'integer', nullable: true, description: 'Minutes; negative when late. Null once answered — the timer stopped.'),
        new OA\Property(property: 'first_response_minutes', type: 'integer', nullable: true, description: 'How long the first reply actually took.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
class SupportPaths
{
}
