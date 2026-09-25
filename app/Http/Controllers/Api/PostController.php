<?php

namespace App\Http\Controllers\Api;

use App\Domain\Social\PostService;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Cursor;
use App\Support\SocialPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GFT-228 — moments (epic D.3d). docs/03 §8: `GET /feed`, `POST /posts`,
 * `POST /posts/{uuid}/like`.
 *
 * The app's Moment screen has two tabs and they are two scopes: `following` is
 * people you follow, `discover` is people you do not. `public` stays for
 * anything that wants the unfiltered timeline.
 */
class PostController extends Controller
{
    public function __construct(protected PostService $posts)
    {
    }

    /** GET /feed */
    public function feed(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope'  => ['sometimes', Rule::in(['following', 'public', 'discover'])],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->respondWithFeed(
            $request->user(),
            $data['scope'] ?? 'following',
            $data['cursor'] ?? null,
            (int) ($data['limit'] ?? 20),
        );
    }

    /** GET /users/{uuid}/posts — one person's moments, filtered to what the caller may see. */
    public function forUser(Request $request, User $profile): JsonResponse
    {
        $data = $request->validate([
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->respondWithFeed(
            $request->user(),
            'user',
            $data['cursor'] ?? null,
            (int) ($data['limit'] ?? 20),
            $profile,
        );
    }

    /** POST /posts */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'         => ['sometimes', Rule::in(Post::TYPES)],
            'body'         => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Six is what the compose screen offers — two full rows of its grid.
            'media_urls'   => ['sometimes', 'array', 'max:6'],
            'media_urls.*' => ['string', 'url', 'max:500'],
            'visibility'   => ['sometimes', Rule::in(Post::VISIBILITIES)],
        ]);

        $post = $this->posts->create($request->user(), $data);

        return ApiResponse::success(
            ['post' => SocialPresenter::post($post, false)],
            'Moment posted',
            201,
        );
    }

    /** GET /posts/{uuid} — 404s rather than 403s when hidden. See SocialException::notVisible(). */
    public function show(Request $request, Post $post): JsonResponse
    {
        $post = $this->posts->show($post, $request->user());

        return ApiResponse::success([
            'post' => SocialPresenter::post($post, $this->likedByCaller($request, $post)),
        ]);
    }

    /** DELETE /posts/{uuid} */
    public function destroy(Request $request, Post $post): JsonResponse
    {
        $this->posts->delete($post, $request->user());

        return ApiResponse::success(null, 'Moment deleted');
    }

    /** POST /posts/{uuid}/like */
    public function like(Request $request, Post $post): JsonResponse
    {
        $post = $this->posts->like($post, $request->user());

        return ApiResponse::success(['post' => SocialPresenter::post($post, true)], 'Liked');
    }

    /**
     * GET /posts/{uuid}/likes — who liked it, newest first.
     *
     * Same cursor shape as the comments list, so the client pages both the same way.
     */
    public function likes(Request $request, Post $post): JsonResponse
    {
        $data = $request->validate([
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->posts->likers(
            $post,
            $request->user(),
            Cursor::decode($data['cursor'] ?? null),
            (int) ($data['limit'] ?? 20),
        );

        return ApiResponse::cursor(
            $page['items']->map(fn (PostLike $like) => SocialPresenter::user($like->user))->all(),
            $page['next_cursor'] === null ? null : Cursor::encode($page['next_cursor']),
        );
    }

    /** DELETE /posts/{uuid}/like */
    public function unlike(Request $request, Post $post): JsonResponse
    {
        $post = $this->posts->unlike($post, $request->user());

        return ApiResponse::success(['post' => SocialPresenter::post($post, false)], 'Unliked');
    }

    // ------------------------------------------------------------------ helpers

    protected function respondWithFeed(?User $viewer, string $scope, ?string $cursor, int $limit, ?User $profile = null): JsonResponse
    {
        $page = $this->posts->feed($viewer, $scope, Cursor::decode($cursor), $limit, $profile);

        $items = $page['items']->map(fn (Post $post) => SocialPresenter::post(
            $post,
            $viewer === null ? null : isset($page['liked'][$post->id]),
        ))->all();

        return ApiResponse::cursor(
            $items,
            $page['next_cursor'] === null ? null : Cursor::encode($page['next_cursor']),
        );
    }

    protected function likedByCaller(Request $request, Post $post): ?bool
    {
        $user = $request->user();

        return $user === null
            ? null
            : $post->likes()->where('user_id', $user->id)->exists();
    }
}
