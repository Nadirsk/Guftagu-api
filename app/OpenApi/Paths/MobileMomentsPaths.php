<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operations for epic D.3d, GFT-228 — App\Http\Controllers\Api\PostController and
 * PostCommentController.
 *
 * Kept separate from MobileAuthPaths.php — same reasoning as that file: **if you change a
 * route, request or response in PostController/PostCommentController, update this file in
 * the same commit.** Feeds and comment threads are cursor-paginated (docs/03 §2.3) — the
 * cursor is opaque, wrapping the last row's id, so never build one by hand.
 */
#[OA\Get(
    path: '/feed',
    summary: 'The moments feed (D.3d)',
    description: '`scope=following` (default) shows people the caller follows; `scope=public` is the public discovery feed. Both are cursor-paginated, newest first.',
    security: [['bearerAuth' => []]],
    tags: ['Moments'],
    parameters: [
        new OA\Parameter(name: 'scope', in: 'query', schema: new OA\Schema(type: 'string', enum: ['following', 'public'], default: 'following')),
        new OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 200)),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PostRow')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/CursorMeta'),
            ])
        ),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/posts',
    summary: 'Post a moment (D.3d)',
    security: [['bearerAuth' => []]],
    tags: ['Moments'],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['text', 'image', 'audio'], default: 'text'),
        new OA\Property(property: 'body', type: 'string', maxLength: 2000, nullable: true),
        new OA\Property(property: 'media_urls', type: 'array', items: new OA\Items(type: 'string', format: 'url'), maxItems: 9),
        new OA\Property(property: 'visibility', type: 'string', enum: ['public', 'followers', 'private'], default: 'public'),
    ])),
    responses: [
        new OA\Response(
            response: 201,
            description: 'Moment posted',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Moment posted'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'post', ref: '#/components/schemas/PostRow'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/posts/{post}',
    summary: 'Show one moment (D.3d)',
    description: 'Returns `404 NOT_FOUND` rather than `403` when the caller may not see it — confirming a hidden post exists is half of what visibility rules are meant to hide.',
    security: [['bearerAuth' => []]],
    tags: ['Moments'],
    parameters: [
        new OA\Parameter(name: 'post', in: 'path', required: true, description: 'Post uuid', schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'post', ref: '#/components/schemas/PostRow'),
            ]),
            new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
        ])),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Delete(
    path: '/posts/{post}',
    summary: 'Delete a moment (D.3d)',
    description: 'The author only.',
    security: [['bearerAuth' => []]],
    tags: ['Moments'],
    parameters: [
        new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Moment deleted', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
        new OA\Response(response: 403, description: '`FORBIDDEN`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/posts/{post}/like',
    summary: 'Like a moment (D.3d)',
    description: 'Idempotent — liking twice does not double-count.',
    security: [['bearerAuth' => []]],
    tags: ['Moments'],
    parameters: [
        new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Liked', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'message', type: 'string', example: 'Liked'),
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'post', ref: '#/components/schemas/PostRow'),
            ]),
            new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
        ])),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Delete(
    path: '/posts/{post}/like',
    summary: 'Unlike a moment (D.3d)',
    security: [['bearerAuth' => []]],
    tags: ['Moments'],
    parameters: [
        new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Unliked', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'message', type: 'string', example: 'Unliked'),
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'post', ref: '#/components/schemas/PostRow'),
            ]),
            new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
        ])),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/users/{profile}/posts',
    summary: "One person's moments (D.3d)",
    description: 'Filtered to what the caller may see — the same visibility rule as `GET /feed`, scoped to one author.',
    security: [['bearerAuth' => []]],
    tags: ['Moments'],
    parameters: [
        new OA\Parameter(name: 'profile', in: 'path', required: true, description: "The author's uuid", schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 200)),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PostRow')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/CursorMeta'),
            ])
        ),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Get(
    path: '/posts/{post}/comments',
    summary: 'Comments on a moment (D.3d)',
    description: 'A deleted comment is returned as a tombstone (`is_deleted: true`, `body`/`author` null) rather than removed, so it keeps its place in a reply thread.',
    security: [['bearerAuth' => []]],
    tags: ['Moments'],
    parameters: [
        new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 200)),
        new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OK',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PostCommentRow')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/CursorMeta'),
            ])
        ),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Post(
    path: '/posts/{post}/comments',
    summary: 'Comment on a moment (D.3d)',
    description: '`parent_uuid` replies to an existing comment; omit it for a top-level comment.',
    security: [['bearerAuth' => []]],
    tags: ['Moments'],
    parameters: [
        new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['body'], properties: [
        new OA\Property(property: 'body', type: 'string', maxLength: 1000),
        new OA\Property(property: 'parent_uuid', type: 'string', format: 'uuid', nullable: true),
    ])),
    responses: [
        new OA\Response(
            response: 201,
            description: 'Comment added',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Comment added'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'comment', ref: '#/components/schemas/PostCommentRow'),
                    new OA\Property(property: 'comment_count', type: 'integer'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 404, description: '`NOT_FOUND`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 422, description: '`VALIDATION_ERROR`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
#[OA\Delete(
    path: '/posts/{post}/comments/{comment}',
    summary: 'Delete a comment (D.3d)',
    description: 'The comment author or the post author. Leaves a tombstone rather than removing the row, so replies underneath keep a parent.',
    security: [['bearerAuth' => []]],
    tags: ['Moments'],
    parameters: [
        new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'comment', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Comment deleted',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Comment deleted'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'comment_count', type: 'integer'),
                ]),
                new OA\Property(property: 'meta', ref: '#/components/schemas/Meta'),
            ])
        ),
        new OA\Response(response: 403, description: '`FORBIDDEN`', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        new OA\Response(response: 404, description: "`NOT_FOUND` — also returned when `comment` does not belong to `post`", content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
    ]
)]
class MobileMomentsPaths
{
}
