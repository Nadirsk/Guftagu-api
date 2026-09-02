<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/** OpenAPI operations for epic A.4 — RoomController and RoomCatalogueController. */
#[OA\Get(
    path: '/admin/rooms',
    summary: 'List rooms (GFT-036)',
    description: 'Pinned rooms first, then by listener count. `is_featured` is the **effective** state — a room whose `featured_until` has lapsed reports `false` here while `featured_flag` still shows the stored column, so an expired badge never renders.',
    security: [['bearerAuth' => []]],
    tags: ['Rooms'],
    parameters: [
        new OA\Parameter(name: 'q', description: 'Room name or code', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['live', 'idle', 'closed', 'force_closed'])),
        new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'featured', description: 'Only rooms featured right now', in: 'query', schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'min_seats', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 20, minimum: 1)),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1)),
        new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['-listener_count', 'listener_count', 'name', '-started_at', '-created_at'])),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RoomRow')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `rooms.view`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/rooms/live',
    summary: 'The live monitoring view (A.4a)',
    description: <<<'MD'
Only rooms whose status is `live`, ordered pinned-first then by listeners.

`realtime.available` is **false** for now: `listener_count` is denormalised into MySQL by
the realtime layer every 10 s, and that layer (E.1) does not exist yet — so these are the
last values written, not live figures. The field is there so the panel can say which it is
showing rather than implying freshness it does not have.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Rooms'],
    parameters: [
        new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'rooms', type: 'array', items: new OA\Items(ref: '#/components/schemas/RoomRow')),
                    new OA\Property(property: 'total', type: 'integer'),
                    new OA\Property(property: 'listeners', type: 'integer', description: 'Sum across the returned rooms'),
                    new OA\Property(property: 'realtime', type: 'object'),
                    new OA\Property(property: 'as_of', type: 'string', format: 'date-time'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `rooms.monitor_live`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/rooms/{room}',
    summary: 'Room detail with the seat map (GFT-037)',
    security: [['bearerAuth' => []]],
    tags: ['Rooms'],
    parameters: [new OA\Parameter(name: 'room', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'room', ref: '#/components/schemas/RoomRow'),
                    new OA\Property(property: 'seats', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'members', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'closure', type: 'object', nullable: true, description: 'Present only on a closed room'),
                    new OA\Property(property: 'pending', type: 'object', description: 'Chat and gift volume arrive with D.2d and the gifting module'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
    ]
)]
#[OA\Post(
    path: '/admin/rooms/{room}/close',
    summary: 'Force-close a room (A.4c)',
    description: <<<'MD'
A reason is mandatory. In one transaction the room is marked `force_closed`, every active
member is marked out with their session duration recorded, every seat is vacated, and the
room loses any featured or pinned slot.

The act is written to **both** `audit_logs` and `moderation_logs` with the admin's
identity, which A.4c requires: audit answers "what did staff change", moderation answers
"what was done to this room and why".

`broadcast.sent` is **false** — the database state is authoritative, but disconnecting
live clients from the Agora channel needs the realtime layer (E.1).
MD,
    security: [['bearerAuth' => []]],
    tags: ['Rooms'],
    parameters: [new OA\Parameter(name: 'room', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
        new OA\Property(property: 'reason', type: 'string', maxLength: 255, example: 'Hate speech from the host.'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Force-closed', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — already closed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `rooms.force_close`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — no reason given', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/rooms/{room}/feature',
    summary: 'Feature or unfeature a room (A.4b)',
    description: 'With `until`, the room stops being featured when that moment passes — **enforced in the query, not by a job**, so a stalled scheduler can never leave a room featured past its window. A closed room cannot be featured.',
    security: [['bearerAuth' => []]],
    tags: ['Rooms'],
    parameters: [new OA\Parameter(name: 'room', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['featured'], properties: [
        new OA\Property(property: 'featured', type: 'boolean'),
        new OA\Property(property: 'until', type: 'string', format: 'date-time', description: 'Must be in the future; omit for open-ended', nullable: true),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — the room is closed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — the window ends in the past', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/rooms/{room}/pin',
    summary: 'Pin or unpin a room',
    security: [['bearerAuth' => []]],
    tags: ['Rooms'],
    parameters: [new OA\Parameter(name: 'room', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['pinned'], properties: [
        new OA\Property(property: 'pinned', type: 'boolean'),
    ])),
    responses: [new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Patch(
    path: '/admin/rooms/{room}/category',
    summary: 'Move a room to another category',
    security: [['bearerAuth' => []]],
    tags: ['Rooms'],
    parameters: [new OA\Parameter(name: 'room', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['category_id'], properties: [
        new OA\Property(property: 'category_id', type: 'integer'),
    ])),
    responses: [new OA\Response(response: 200, description: 'Category changed', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/rooms/{room}/seats/{seat}/lock',
    summary: 'Lock or unlock one seat (C.2b)',
    description: 'Locking an **occupied** seat turns the occupant out — a person seated on a locked seat is a state the app cannot represent. Recorded in `moderation_logs`.',
    security: [['bearerAuth' => []]],
    tags: ['Rooms'],
    parameters: [
        new OA\Parameter(name: 'room', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'seat', description: 'Seat number, 1-based', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['locked'], properties: [
        new OA\Property(property: 'locked', type: 'boolean'),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND` — no such seat in this room', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]

// ------------------------------------------------------------------ catalogue

#[OA\Get(
    path: '/admin/room-categories',
    summary: 'Room categories (A.4d)',
    description: 'Readable with `rooms.view` — every screen showing a room needs its category name. Changing the catalogue needs `rooms.theme_manage`.',
    security: [['bearerAuth' => []]],
    tags: ['Room catalogue'],
    parameters: [new OA\Parameter(name: 'include_inactive', in: 'query', schema: new OA\Schema(type: 'boolean'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/room-categories',
    summary: 'Create a category',
    security: [['bearerAuth' => []]],
    tags: ['Room catalogue'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['key', 'name_en'], properties: [
        new OA\Property(property: 'key', type: 'string', pattern: '^[a-z][a-z0-9_]*$', example: 'poetry'),
        new OA\Property(property: 'name_en', type: 'string', example: 'Poetry'),
        new OA\Property(property: 'name_hi', type: 'string', example: 'कविता', nullable: true),
        new OA\Property(property: 'icon_url', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'sort_order', type: 'integer'),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ])),
    responses: [
        new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`PERMISSION_DENIED` — needs `rooms.theme_manage`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Patch(
    path: '/admin/room-categories/{category}',
    summary: 'Update a category',
    security: [['bearerAuth' => []]],
    tags: ['Room catalogue'],
    parameters: [new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(type: 'object')),
    responses: [new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Delete(
    path: '/admin/room-categories/{category}',
    summary: 'Delete an unused category',
    description: 'Refused while rooms still use it — deleting would orphan them into an uncategorised state. Deactivate instead: that hides it from the app and leaves history intact.',
    security: [['bearerAuth' => []]],
    tags: ['Room catalogue'],
    parameters: [new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — in use; `details.room_count` says how many', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/admin/room-themes',
    summary: 'Room themes (A.4d)',
    security: [['bearerAuth' => []]],
    tags: ['Room catalogue'],
    parameters: [new OA\Parameter(name: 'include_inactive', in: 'query', schema: new OA\Schema(type: 'boolean'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/room-themes',
    summary: 'Create a theme',
    description: '`required_vip_tier_id` is stored but **not** validated against a tier table — `vip_tiers` arrives with A.6, so the gate can be configured ahead of the tiers existing. The app enforces `VIP_TIER_REQUIRED` once both are present.',
    security: [['bearerAuth' => []]],
    tags: ['Room catalogue'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Royal Durbar'),
        new OA\Property(property: 'background_url', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'preview_url', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'is_premium', type: 'boolean'),
        new OA\Property(property: 'required_vip_tier_id', type: 'integer', nullable: true),
        new OA\Property(property: 'coin_price', type: 'integer', minimum: 0),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ])),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Patch(
    path: '/admin/room-themes/{theme}',
    summary: 'Update a theme',
    security: [['bearerAuth' => []]],
    tags: ['Room catalogue'],
    parameters: [new OA\Parameter(name: 'theme', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(type: 'object')),
    responses: [new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Delete(
    path: '/admin/room-themes/{theme}',
    summary: 'Delete an unused theme',
    security: [['bearerAuth' => []]],
    tags: ['Room catalogue'],
    parameters: [new OA\Parameter(name: 'theme', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [
        new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — in use', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]

// -------------------------------------------------------- live enforcement

#[OA\Post(
    path: '/admin/rooms/{room}/silent-join',
    summary: 'Silently observe a room (C.1b)',
    description: <<<'MD'
The "silent" half is enforced at the data layer: this writes to neither `room_members` nor
`room_seats`, so the moderator appears in no participant list and no `member.joined` event
is ever produced — there is nothing to suppress because nothing is created.

What is written is what C.1b makes mandatory: an `audit_logs` and `moderation_logs` row
naming the room, the moderator and the timestamp.

**Audio is not subscribed.** Actually hearing the room needs an Agora RTC token, and this
environment has no Agora credentials configured — the response says so plainly rather than
returning a token that would not work.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Rooms'],
    parameters: [new OA\Parameter(name: 'room', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/rooms/{room}/seats/{seat}/mute',
    summary: 'Server-forced mute (C.2a)',
    description: 'Sets `room_seats.is_muted_by_host` and writes a room-scoped `user_sanctions` row of type `mute`. The duration is **derived**, not enforced by a job — a lapsed mute stops applying on its own, the same rule every other time-bounded state in this codebase follows.',
    security: [['bearerAuth' => []]],
    tags: ['Rooms'],
    parameters: [
        new OA\Parameter(name: 'room', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'seat', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['reason'],
        properties: [
            new OA\Property(property: 'duration_minutes', type: 'integer', nullable: true, description: 'Null means indefinite, until unmuted.'),
            new OA\Property(property: 'reason', type: 'string'),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — the seat is empty', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/rooms/{room}/seats/{seat}/unmute',
    summary: 'Lift a server-forced mute',
    security: [['bearerAuth' => []]],
    tags: ['Rooms'],
    parameters: [
        new OA\Parameter(name: 'room', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'seat', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
#[OA\Post(
    path: '/admin/rooms/{room}/members/{user}/kick',
    summary: 'Remove from the room, with a re-entry block (C.2b)',
    description: 'Closes out the `room_members` row exactly like a normal departure — same `left_at`, same computed duration — so the presence history reads identically either way; only the sanction says this one was not voluntary. The re-entry block is that sanction, derived from the clock: `Room::isBlockedForUser()` reads it at read time rather than a job clearing a flag.',
    security: [['bearerAuth' => []]],
    tags: ['Rooms'],
    parameters: [
        new OA\Parameter(name: 'room', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['reason'],
        properties: [
            new OA\Property(property: 'reentry_block_minutes', type: 'integer', nullable: true, description: 'Null means indefinite, until reversed.'),
            new OA\Property(property: 'reason', type: 'string'),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 400, description: '`BAD_REQUEST` — not currently in the room', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/admin/rooms/{room}/warn',
    summary: 'Issue an in-room warning (C.2c)',
    description: <<<'MD'
Writes a room-scoped `user_sanctions` row of type `warning` and an in-app `Notification` to
the warned user — the push half of C.2c.

The chat half — a system message in the room's live chat — cannot be written yet: there is
no `messages` table until D.4 lands with the mobile app. `chat_posted` is `false` and the
response says why, rather than silently doing half the job.
MD,
    security: [['bearerAuth' => []]],
    tags: ['Rooms'],
    parameters: [new OA\Parameter(name: 'room', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['user_id', 'message'],
        properties: [
            new OA\Property(property: 'user_id', type: 'integer'),
            new OA\Property(property: 'message', type: 'string'),
        ]
    )),
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope'))]
)]
class RoomPaths
{
}
