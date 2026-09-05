<?php

namespace App\Http\Controllers\Api;

use App\Domain\Social\PostService;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostComment;
use App\Support\ApiResponse;
use App\Support\Cursor;
use App\Support\SocialPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GFT-228 — comments on a moment (D.3d). docs/03 §8: `POST /posts/{uuid}/comments`. */
class PostCommentController extends Controller
{
    public function __construct(protected PostService $posts)
    {
    }

    /** GET /posts/{uuid}/comments */
    public function index(Request $request, Post $post): JsonResponse
    {
        $data = $request->validate([
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->posts->comments(
            $post,
            $request->user(),
            Cursor::decode($data['cursor'] ?? null),
            (int) ($data['limit'] ?? 20),
        );

        // Resolve every parent uuid in one query rather than lazily per row — a page of
        // twenty replies would otherwise be twenty extra selects.
        $parentUuids = PostComment::query()
            ->whereIn('id', $page['items']->pluck('parent_id')->filter()->unique())
            ->pluck('uuid', 'id')
            ->all();

        $items = $page['items']
            ->map(fn (PostComment $c) => SocialPresenter::comment($c, $parentUuids) + ['post_uuid' => $post->uuid])
            ->all();

        return ApiResponse::cursor(
            $items,
            $page['next_cursor'] === null ? null : Cursor::encode($page['next_cursor']),
        );
    }

    /** POST /posts/{uuid}/comments */
    public function store(Request $request, Post $post): JsonResponse
    {
        $data = $request->validate([
            'body'        => ['required', 'string', 'max:1000'],
            'parent_uuid' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $comment = $this->posts->comment(
            $post,
            $request->user(),
            $data['body'],
            $data['parent_uuid'] ?? null,
        );

        return ApiResponse::success([
            'comment'       => SocialPresenter::comment($comment) + ['post_uuid' => $post->uuid],
            'comment_count' => (int) $post->fresh()->comment_count,
        ], 'Comment added', 201);
    }

    /** DELETE /posts/{post}/comments/{comment} */
    public function destroy(Request $request, Post $post, PostComment $comment): JsonResponse
    {
        // Bound independently, so the pairing has to be checked: `/posts/A/comments/<id on
        // post B>` would otherwise let the author of A delete a comment on B.
        if ($comment->post_id !== $post->id) {
            return ApiResponse::error('NOT_FOUND', 'Resource not found', null, 404);
        }

        $this->posts->deleteComment($comment, $request->user());

        return ApiResponse::success([
            'comment_count' => (int) $post->fresh()->comment_count,
        ], 'Comment deleted');
    }
}
