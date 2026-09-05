<?php

namespace App\Http\Controllers\Api;

use App\Domain\Social\SocialService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\SocialPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GFT-224 — follow, unfollow and the two lists (epic D.3b).
 *
 * D.3b: *"their follower count increases immediately for both of us"* — which is why the
 * follow and unfollow responses carry both counts rather than an empty 204. The client has
 * the new numbers before the socket frame arrives, so the button never shows a stale total.
 */
class FollowController extends Controller
{
    public function __construct(protected SocialService $social)
    {
    }

    /** POST /users/{uuid}/follow */
    public function follow(Request $request, User $profile): JsonResponse
    {
        $counts = $this->social->follow($request->user(), $profile);

        return ApiResponse::success([
            'user'            => SocialPresenter::user($profile),
            'is_following'    => true,
            'follower_count'  => $counts['followers'],
            'following_count' => $counts['following'],
        ], 'Following');
    }

    /** DELETE /users/{uuid}/follow */
    public function unfollow(Request $request, User $profile): JsonResponse
    {
        $counts = $this->social->unfollow($request->user(), $profile);

        return ApiResponse::success([
            'user'            => SocialPresenter::user($profile),
            'is_following'    => false,
            'follower_count'  => $counts['followers'],
            'following_count' => $counts['following'],
        ], 'Unfollowed');
    }

    /** GET /users/{uuid}/followers */
    public function followers(Request $request, User $profile): JsonResponse
    {
        return $this->list($request, $profile, 'followers');
    }

    /** GET /users/{uuid}/following */
    public function following(Request $request, User $profile): JsonResponse
    {
        return $this->list($request, $profile, 'following');
    }

    protected function list(Request $request, User $profile, string $direction): JsonResponse
    {
        $data = $request->validate([
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->social->connections(
            $profile,
            $direction,
            $request->user(),
            (int) ($data['per_page'] ?? 20),
            (int) ($data['page'] ?? 1),
        );

        $viewer = $request->user();

        // Which of these the caller already follows, in one query — the follow-back button
        // on every row is otherwise an N+1.
        $followedByMe = $viewer === null
            ? []
            : $viewer->following()
                ->whereIn('users.id', collect($paginator->items())->pluck('id'))
                ->pluck('users.id')
                ->flip()
                ->all();

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (User $u) => SocialPresenter::user($u) + ['is_following' => isset($followedByMe[$u->id])]
        )->all());
    }
}
