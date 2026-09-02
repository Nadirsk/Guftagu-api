<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/** OpenAPI operations for epic A.5 — BannedWordController and ModerationController. */
#[OA\Get(
    path: '/admin/moderation/banned-words',
    summary: 'The content-filter word list (A.5a)',
    description: <<<'MD'
Three severities, three outcomes:

| severity  | what happens to the content |
|-----------|-----------------------------|
| `block`   | refused outright |
| `replace` | delivered with the term swapped, and flagged |
| `flag`    | delivered untouched, and flagged for review |

An empty `scope` means the rule applies everywhere — narrowing is opt-in, so a word added
without thinking about scope is still enforced. `applies_everywhere` says so explicitly
rather than making the client infer it from an empty array.

The list is cached for ten minutes because it runs on every chat message, room name, bio
and DM. Any write here flushes that cache, so a new rule bites at once.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [
        new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'severity', in: 'query', schema: new OA\Schema(type: 'string', enum: ['block', 'flag', 'replace'])),
        new OA\Parameter(name: 'language', in: 'query', schema: new OA\Schema(type: 'string', example: 'en')),
        new OA\Parameter(name: 'active', in: 'query', schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 200, minimum: 1)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BannedWord')),
            new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
        ])),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `moderation.bannedwords_manage`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/moderation/banned-words',
    summary: 'Add a word or pattern',
    description: 'A `is_regex` rule is compiled before it is stored — an uncompilable pattern is refused with a 422 rather than saved as a rule that silently never fires.',
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['word', 'severity'],
        properties: [
            new OA\Property(property: 'word', type: 'string', example: 'free coins'),
            new OA\Property(property: 'language', type: 'string', example: 'en'),
            new OA\Property(property: 'severity', type: 'string', enum: ['block', 'flag', 'replace']),
            new OA\Property(property: 'replacement', type: 'string', nullable: true, example: '****'),
            new OA\Property(property: 'scope', type: 'array', items: new OA\Items(type: 'string', enum: ['room_name', 'chat', 'bio', 'dm']), description: 'Empty or omitted means every surface.'),
            new OA\Property(property: 'is_regex', type: 'boolean', example: false),
            new OA\Property(property: 'is_active', type: 'boolean', example: true),
        ]
    )),
    responses: [
        new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — duplicate for this language, or an invalid pattern', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Patch(
    path: '/admin/moderation/banned-words/{bannedWord}',
    summary: 'Edit a word',
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [new OA\Parameter(name: 'bannedWord', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BannedWord')),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Delete(
    path: '/admin/moderation/banned-words/{bannedWord}',
    summary: 'Remove a word',
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [new OA\Parameter(name: 'bannedWord', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/moderation/banned-words/import',
    summary: 'Bulk import',
    description: 'Reports each row\'s outcome instead of failing the batch on one duplicate — a 400-word paste that dies on line 3 is worse than useless. `skipped` lists what was already on the list for that language.',
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['words'],
        properties: [
            new OA\Property(property: 'words', type: 'array', items: new OA\Items(type: 'string'), example: ['scam', 'free recharge']),
            new OA\Property(property: 'language', type: 'string', example: 'en'),
            new OA\Property(property: 'severity', type: 'string', enum: ['block', 'flag', 'replace']),
            new OA\Property(property: 'replacement', type: 'string', nullable: true),
        ]
    )),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/moderation/filter-test',
    summary: 'Try a phrase against the live list',
    description: <<<'MD'
Runs the **same** `ContentFilter` the platform runs, so what comes back here is what would
actually happen to that message. A dry run never writes a `content_flags` row — the
response says `flag_recorded: false` so nobody has to wonder whether testing polluted the
review queue.

Read-only, so it sits behind `moderation.logs_view` rather than the manage permission.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['text'],
        properties: [
            new OA\Property(property: 'text', type: 'string', example: 'you are an idiot'),
            new OA\Property(property: 'scope', type: 'string', enum: ['room_name', 'chat', 'bio', 'dm']),
            new OA\Property(property: 'language', type: 'string', nullable: true),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', ref: '#/components/schemas/FilterTestResult'),
        ])),
    ]
)]
#[OA\Get(
    path: '/admin/moderation/flags',
    summary: 'What the filter caught and let through',
    description: 'Only `replace` and `flag` matches land here — a blocked message was never delivered, so there is nothing to review.',
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [
        new OA\Parameter(name: 'status', in: 'query', description: 'Defaults to `open`.', schema: new OA\Schema(type: 'string', enum: ['open', 'reviewed', 'dismissed'])),
        new OA\Parameter(name: 'content_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['room_name', 'chat', 'bio', 'dm'])),
    ],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/moderation/flags/{flag}/review',
    summary: 'Close a content flag',
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [new OA\Parameter(name: 'flag', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['reviewed', 'dismissed']),
    ])),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/reports',
    summary: 'The reports queue (A.5b)',
    description: <<<'MD'
Ordered `critical → high → medium → low`, oldest first inside a priority. That ordering is
explicit `FIELD()` SQL, not `ORDER BY priority` — alphabetically, "critical" sorts *after*
"high", which would bury the reports that matter most.

Defaults to the open queue (`open`, `assigned`, `escalated`), because that is what a
moderator opens this screen to work through. Pass `status` to see resolved ones.

`waiting_minutes` is how long an open report has been sitting — the number a queue is
actually judged on.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['open', 'assigned', 'actioned', 'dismissed', 'escalated'])),
        new OA\Parameter(name: 'priority', in: 'query', schema: new OA\Schema(type: 'string', enum: ['critical', 'high', 'medium', 'low'])),
        new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string', enum: ['abuse', 'nudity', 'harassment', 'spam', 'fraud', 'underage', 'other'])),
        new OA\Parameter(name: 'mine', in: 'query', description: 'Only reports assigned to the caller.', schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ReportRow')),
            new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
        ])),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `reports.view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/reports/summary',
    summary: 'Queue totals by lane',
    description: 'The shape of the backlog before diving into it: open count per priority, how many are unassigned, how many are the caller\'s, and the timestamp of the oldest critical.',
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/reports/{report}',
    summary: 'One report with its evidence and the target\'s history',
    description: 'Includes `prior_sanctions` and `open_reports` for the target, because deciding a report needs to know whether this is a first offence. Room, message and post targets resolve to `null` until those modules land — `target_note` says so rather than returning an empty object.',
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/reports/{report}/assign',
    summary: 'Assign a report to a moderator',
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'admin_user_id', type: 'integer', example: 3),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — the report is already resolved', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/reports/{report}/action',
    summary: 'Action a report (C.3c)',
    description: <<<'MD'
The sanction and the report resolution happen in one transaction. A report marked actioned
with no sanction behind it, and a ban with no report explaining it, are both states someone
has to untangle later.

`warn`, `mute` and `kick` are recorded but do not lock the account — mute and kick are
room-scoped and need the realtime layer to take effect. `ban_temp` and `ban_permanent` go
through the same `SanctionService` the user-management screens use, rather than a second
code path that writes `users.status`.

A `note` is always required: every action has to be explicable afterwards.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['action', 'note'],
        properties: [
            new OA\Property(property: 'action', type: 'string', enum: ['warn', 'mute', 'kick', 'ban_temp', 'ban_permanent', 'dismiss', 'escalate']),
            new OA\Property(property: 'duration_minutes', type: 'integer', nullable: true, description: 'For `ban_temp`, `mute`, `kick`. Defaults to 1440 for a temp ban.', example: 1440),
            new OA\Property(property: 'note', type: 'string', example: 'Third warning for harassment in voice rooms.'),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `reports.action`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/reports/{report}/dismiss',
    summary: 'Dismiss a report with a note',
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'note', type: 'string', example: 'No policy breach — reporter disagreed with an opinion.'),
    ])),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/reports/{report}/escalate',
    summary: 'Escalate to a senior admin',
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'to_admin_id', type: 'integer', example: 1),
        new OA\Property(property: 'note', type: 'string'),
    ])),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/moderation/actions/{action}/reverse',
    summary: 'Undo a moderation action (A.5c)',
    description: <<<'MD'
The reversal is written onto the original action rather than as an independent row, because
oversight needs to know **which** action was wrong, not merely that a reversal happened.
That is also what `reversal_rate` in the stats endpoint counts.

Reversing a ban actually lets the person back in — otherwise the reversal is paperwork.

Needs `moderation.reverse_action`, which is deliberately outside the Moderator baseline:
undoing a colleague's decision is oversight, not moderation.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [new OA\Parameter(name: 'action', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'reason', type: 'string', example: 'Clip shows the other party started it; ban was not warranted.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — already reversed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/moderation/sanctions',
    summary: 'Every sanction, with whether it is still in force (A.5d)',
    description: <<<'MD'
`is_active` is the stored flag; **`in_force` is whether it still bites right now**. Those
differ the moment a window lapses, and the difference matters: a 24-hour ban whose window
has passed leaves `is_active = true` in the row until `moderation:expire-sanctions` runs,
but the account is already usable.

The release does not wait for that job — `User::effectiveStatus()` derives it — so a
stalled scheduler delays the log entry, never someone getting their account back.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [
        new OA\Parameter(name: 'user_id', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'active_only', in: 'query', schema: new OA\Schema(type: 'boolean')),
    ],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/moderation/logs',
    summary: 'The moderation log (A.5c, C.4c)',
    description: 'A `by` of `system` means time did it, not a person — sanction expiries land here with no actor.',
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [
        new OA\Parameter(name: 'admin_user_id', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'action', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/moderation/stats',
    summary: 'Per-moderator activity and reversal rate (A.5c)',
    description: <<<'MD'
Response time is measured **from when the report arrived**, not from assignment — a report
left unassigned for a day is still a slow response, and measuring from assignment would
hide exactly the failure this view exists to catch.

`reversal_rate` is reversed ÷ actions over the window. It is the number oversight is for.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    parameters: [new OA\Parameter(name: 'days', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 90, minimum: 1, example: 7))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Get(
    path: '/admin/moderation/alerts',
    summary: 'Open critical reports (C.5a)',
    description: 'The critical lane on its own, capped at 20, for a banner or a notification poll.',
    security: [['bearerAuth' => []]],
    tags: ['Moderation'],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Schema(
    schema: 'BannedWord',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'word', type: 'string'),
        new OA\Property(property: 'language', type: 'string', example: 'en'),
        new OA\Property(property: 'severity', type: 'string', enum: ['block', 'flag', 'replace']),
        new OA\Property(property: 'replacement', type: 'string', nullable: true),
        new OA\Property(property: 'scope', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'applies_everywhere', type: 'boolean'),
        new OA\Property(property: 'is_regex', type: 'boolean'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_by', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'FilterTestResult',
    properties: [
        new OA\Property(property: 'original', type: 'string'),
        new OA\Property(property: 'filtered', type: 'string', description: 'Unchanged when the outcome is `block` — nothing is delivered, so there is nothing to clean up.'),
        new OA\Property(property: 'severity', type: 'string', nullable: true, enum: ['block', 'flag', 'replace'], description: 'Null when nothing matched.'),
        new OA\Property(property: 'matches', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'word', type: 'string'),
            new OA\Property(property: 'severity', type: 'string'),
        ], type: 'object')),
        new OA\Property(property: 'outcome', type: 'string', description: 'Plain-English restatement of what would happen.'),
        new OA\Property(property: 'flag_recorded', type: 'boolean', example: false),
    ]
)]
#[OA\Schema(
    schema: 'ReportRow',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'uuid', type: 'string'),
        new OA\Property(property: 'target_type', type: 'string', enum: ['user', 'room', 'message', 'post']),
        new OA\Property(property: 'target_id', type: 'string'),
        new OA\Property(property: 'category', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'evidence_urls', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
        new OA\Property(property: 'priority', type: 'string', enum: ['critical', 'high', 'medium', 'low']),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'is_open', type: 'boolean'),
        new OA\Property(property: 'assigned_to', type: 'string', nullable: true),
        new OA\Property(property: 'resolved_by', type: 'string', nullable: true),
        new OA\Property(property: 'waiting_minutes', type: 'integer', nullable: true, description: 'Null once resolved.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
class ModerationPaths
{
}
