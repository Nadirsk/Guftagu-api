<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operations for epics D.3b/D.9c — App\Http\Controllers\Api\FriendController,
 * FollowController, BlockController and VisitorController.
 *
 * Kept separate from MobileAuthPaths.php — same reasoning as that file: **if you change a
 * route, request or response in one of these controllers, update this file in the same
 * commit.** `{profile}` below is always a User bound by uuid (routes/api.php), never the
 * numeric id the admin panel addresses users by.
 */
#[OA\Get(
    path: '/friends',
    summary: 'The friend list — a mutual follow (GFT-224, D.3b)',
    description: 'There is no request/accept flow: following someone who already follows you makes you friends. To add a friend call `POST /users/{uuid}/follow`; to remove one, the matching `DELETE`.',
    security: [['bearerAuth' => []]],
    tags: ['Friends'],
    parameters: [
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'guftagu_id', type: 'string', example: 'GF8420156'),
                        new OA\Property(property: 'display_name', type: 'string', nullable: true),
                        new OA\Property(property: 'avatar_url', type: 'string', nullable: true),
                        new OA\Property(property: 'last_active_at', type: 'string', format: 'date-time', nullable: true),
                    ])
                ),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/users/{profile}/follow',
    summary: 'Follow a user (D.3b)',
    description: 'Idempotent — following someone already followed is a no-op, not a 422. Both the follower and following counts are returned so the button never shows a stale total while the socket frame is in flight.',
    security: [['bearerAuth' => []]],
    tags: ['Follow'],
    parameters: [
        new OA\Parameter(name: 'profile', in: 'path', required: true, description: 'The target user\'s uuid', schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Following',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Following'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/SocialUserCard'),
                    new OA\Property(property: 'is_following', type: 'boolean', example: true),
                    new OA\Property(property: 'follower_count', type: 'integer'),
                    new OA\Property(property: 'following_count', type: 'integer'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 403, description: '`BLOCKED` — a block exists between these accounts, in either direction', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — cannot follow yourself', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Delete(
    path: '/users/{profile}/follow',
    summary: 'Unfollow a user (D.3b)',
    description: 'Also ends a friendship, since a friend is nothing but a mutual follow.',
    security: [['bearerAuth' => []]],
    tags: ['Follow'],
    parameters: [
        new OA\Parameter(name: 'profile', in: 'path', required: true, description: 'The target user\'s uuid', schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Unfollowed',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Unfollowed'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/SocialUserCard'),
                    new OA\Property(property: 'is_following', type: 'boolean', example: false),
                    new OA\Property(property: 'follower_count', type: 'integer'),
                    new OA\Property(property: 'following_count', type: 'integer'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/users/{profile}/followers',
    summary: "A user's followers (D.3b)",
    description: '`is_following` on every row is resolved for the caller in one query, so the follow-back button never costs an N+1.',
    security: [['bearerAuth' => []]],
    tags: ['Follow'],
    parameters: [
        new OA\Parameter(name: 'profile', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'guftagu_id', type: 'string', example: 'GF8420156'),
                        new OA\Property(property: 'display_name', type: 'string', nullable: true),
                        new OA\Property(property: 'avatar_url', type: 'string', nullable: true),
                        new OA\Property(property: 'is_following', type: 'boolean', description: 'Whether the caller already follows this row'),
                    ])
                ),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/users/{profile}/following',
    summary: 'Who a user follows (D.3b)',
    security: [['bearerAuth' => []]],
    tags: ['Follow'],
    parameters: [
        new OA\Parameter(name: 'profile', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(properties: [
                        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'guftagu_id', type: 'string', example: 'GF8420156'),
                        new OA\Property(property: 'display_name', type: 'string', nullable: true),
                        new OA\Property(property: 'avatar_url', type: 'string', nullable: true),
                        new OA\Property(property: 'is_following', type: 'boolean'),
                    ])
                ),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/blocks',
    summary: 'The block list (GFT-237, D.9c)',
    security: [['bearerAuth' => []]],
    tags: ['Blocks'],
    parameters: [
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/SocialUserCard'),
                    new OA\Property(property: 'reason', type: 'string', nullable: true),
                    new OA\Property(property: 'blocked_at', type: 'string', format: 'date-time', nullable: true),
                ])),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/users/{profile}/block',
    summary: 'Block a user (GFT-237, D.9c)',
    description: 'A block also breaks any existing follow in both directions, hides each side from the other\'s follower list, search and feed, and refuses DMs and calls between them. Enforcement lives in `Block::existsBetween()`, consulted by every send/feed/search path — this endpoint only manages the row.',
    security: [['bearerAuth' => []]],
    tags: ['Blocks'],
    parameters: [
        new OA\Parameter(name: 'profile', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'reason', type: 'string', maxLength: 255, nullable: true),
    ])),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Blocked',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Blocked'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/SocialUserCard'),
                    new OA\Property(property: 'is_blocked', type: 'boolean', example: true),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR` — cannot block yourself', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Delete(
    path: '/users/{profile}/block',
    summary: 'Unblock a user (GFT-237, D.9c)',
    security: [['bearerAuth' => []]],
    tags: ['Blocks'],
    parameters: [
        new OA\Parameter(name: 'profile', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Unblocked',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Unblocked'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/SocialUserCard'),
                    new OA\Property(property: 'is_blocked', type: 'boolean', example: false),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/users/{profile}/visit',
    summary: "Record a profile visit (GFT-227, D.3b)",
    description: 'Its own POST rather than a side effect of `GET /users/{uuid}` — a GET that writes cannot be retried, prefetched or cached, and every client library does at least one of those.',
    security: [['bearerAuth' => []]],
    tags: ['Visitors'],
    parameters: [
        new OA\Parameter(name: 'profile', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Visit recorded',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Visit recorded'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'is_new_visitor', type: 'boolean', description: 'False on a repeat visit within the same day'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/users/{profile}/visitors',
    summary: "Who visited a profile — the caller's own list only (GFT-227)",
    description: "Someone else's visitor list says who has been looking at them, which is theirs to know and nobody else's.",
    security: [['bearerAuth' => []]],
    tags: ['Visitors'],
    parameters: [
        new OA\Parameter(name: 'profile', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/SocialUserCard'),
                    new OA\Property(property: 'visit_count', type: 'integer', example: 1),
                    new OA\Property(property: 'visited_at', type: 'string', format: 'date-time', nullable: true),
                ])),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ])
        ),
        new OA\Response(response: 401, description: '`UNAUTHENTICATED`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 403, description: '`FORBIDDEN` — you can only see your own visitors', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
class MobileSocialPaths
{
}
