<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operations for epic D.4, GFT-235/236 — App\Http\Controllers\Api\ConversationController
 * and MessageController.
 *
 * Kept separate from MobileAuthPaths.php — same reasoning as that file: **if you change a
 * route, request or response in ConversationController/MessageController, update this file in
 * the same commit.** DMs require a mutual follow (`NOT_FRIENDS`) and a block refuses either
 * direction (`BLOCKED`) — both are 403s enforced in ChatService, not middleware, since "may I
 * message this person" depends on the pair.
 */
#[OA\Get(
    path: '/conversations',
    summary: 'The DM list (D.4)',
    security: [['bearerAuth' => []]],
    tags: ['Messaging'],
    parameters: [
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 30)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ConversationRow')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/conversations',
    summary: 'Open a direct thread or start a group (D.4)',
    description: '`user_uuid` opens (or reopens) a direct thread — find-or-create, so tapping "message" twice never produces two threads with half the history in each. `title` + `member_uuids` starts a group instead; exactly one of the two shapes is required.',
    security: [['bearerAuth' => []]],
    tags: ['Messaging'],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['direct', 'group'], default: 'direct'),
        new OA\Property(property: 'user_uuid', type: 'string', format: 'uuid', description: 'Required without member_uuids', nullable: true),
        new OA\Property(property: 'title', type: 'string', maxLength: 100, description: 'Required with member_uuids', nullable: true),
        new OA\Property(property: 'member_uuids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), minItems: 1, maxItems: 49),
    ])),
    responses: [
        new OA\Response(
            response: 201,
            description: 'Conversation ready',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Conversation ready'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'conversation', ref: '#/components/schemas/ConversationRow'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`BLOCKED` — a block exists between the parties, or `NOT_FRIENDS` — direct messaging requires a mutual follow', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — e.g. starting a conversation with yourself, or a group with no other member', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/conversations/{conversation}',
    summary: 'Show one conversation (D.4)',
    security: [['bearerAuth' => []]],
    tags: ['Messaging'],
    parameters: [
        new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'conversation', ref: '#/components/schemas/ConversationRow'),
            ]),
            new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
        ])),
        new OA\Response(response: 403, description: '`FORBIDDEN` — not a participant', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/conversations/{conversation}/read',
    summary: 'Mark a thread read — the blue tick (D.4)',
    description: 'Also clears the conversation\'s unread badge. Every other participant\'s messages up to this point tick from `delivered` to `read` on their next fetch.',
    security: [['bearerAuth' => []]],
    tags: ['Messaging'],
    parameters: [
        new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Marked read',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Marked read'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'unread_count', type: 'integer', example: 0),
                    new OA\Property(property: 'last_read_message_uuid', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'last_delivered_message_uuid', type: 'string', format: 'uuid', nullable: true),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`FORBIDDEN` — not a participant', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/messages/delivered',
    summary: 'App-resume delivery sweep across every thread (D.4)',
    description: 'A phone that was offline comes back to many threads behind; this is one call to mark all of them delivered instead of one round trip per thread. Static segment, registered before `conversations/{conversation}/delivered` — `messages` would otherwise be parsed as a conversation uuid.',
    security: [['bearerAuth' => []]],
    tags: ['Messaging'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Marked delivered',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'threads_updated', type: 'integer'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/conversations/{conversation}/delivered',
    summary: 'Mark a thread delivered — the second tick (D.4)',
    description: 'Called when a `message.new` socket frame lands; the thread does not have to be open, which is the whole point of a delivered receipt.',
    security: [['bearerAuth' => []]],
    tags: ['Messaging'],
    parameters: [
        new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Marked delivered',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'last_delivered_message_uuid', type: 'string', format: 'uuid', nullable: true),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`FORBIDDEN` — not a participant', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/conversations/{conversation}/mute',
    summary: 'Mute or unmute a thread (D.4)',
    description: 'Defaults to muting when `muted` is omitted.',
    security: [['bearerAuth' => []]],
    tags: ['Messaging'],
    parameters: [
        new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'muted', type: 'boolean', default: true),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'is_muted', type: 'boolean'),
            ]),
            new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
        ])),
        new OA\Response(response: 403, description: '`FORBIDDEN` — not a participant', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/conversations/{conversation}/typing',
    summary: 'Broadcast a typing indicator (D.4)',
    description: 'Nothing is persisted — a typing state is true for about two seconds and is forgotten once the socket frame goes out.',
    security: [['bearerAuth' => []]],
    tags: ['Messaging'],
    parameters: [
        new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'typing', type: 'boolean', default: true),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`FORBIDDEN` — not a participant', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/conversations/{conversation}/leave',
    summary: 'Leave a group conversation (D.4)',
    security: [['bearerAuth' => []]],
    tags: ['Messaging'],
    parameters: [
        new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Left the conversation', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`FORBIDDEN` — not a participant', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/conversations/{conversation}/messages',
    summary: 'Messages in a thread (D.4)',
    description: 'Newest first, cursor-paginated — a thread grows at the top while scrolling back through it, which is exactly the case `OFFSET` pagination gets wrong.',
    security: [['bearerAuth' => []]],
    tags: ['Messaging'],
    parameters: [
        new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 200)),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 30)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MessageRow')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/CursorMeta'),
            ])
        ),
        new OA\Response(response: 403, description: '`FORBIDDEN` — not a participant', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/conversations/{conversation}/messages',
    summary: 'Send a message (D.4)',
    description: 'Throttled harder than reads — DM send is capped at 30/min/user (docs/03 §16). `reply_to_uuid` quotes an earlier message in the same thread. The response `status` is always `sent` at this instant — nobody else can have received it yet.',
    security: [['bearerAuth' => []]],
    tags: ['Messaging'],
    parameters: [
        new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['text', 'image', 'audio', 'video', 'gift', 'system'], default: 'text'),
        new OA\Property(property: 'body', type: 'string', maxLength: 4000, nullable: true),
        new OA\Property(property: 'media_url', type: 'string', format: 'url', maxLength: 500, nullable: true),
        new OA\Property(property: 'media_meta', type: 'object', nullable: true),
        new OA\Property(property: 'reply_to_uuid', type: 'string', format: 'uuid', nullable: true),
    ])),
    responses: [
        new OA\Response(
            response: 201,
            description: 'Sent',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Sent'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'message', ref: '#/components/schemas/MessageRow'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`FORBIDDEN` — not a participant', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — an empty message, or `BANNED_WORD_DETECTED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 429, description: '`RATE_LIMITED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Delete(
    path: '/conversations/{conversation}/messages/{message}',
    summary: 'Delete a message (D.4)',
    description: '`?for_everyone=true` retracts it for the whole thread and is the sender\'s privilege alone; without the flag it is hidden for the caller only.',
    security: [['bearerAuth' => []]],
    tags: ['Messaging'],
    parameters: [
        new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'message', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'for_everyone', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`FORBIDDEN` — only the sender may delete for everyone', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: "`NOT_FOUND` — also returned when `message` does not belong to `conversation`", content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
class MobileMessagingPaths
{
}
