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
 * GFT-224 — the friend list (epic D.3b).
 *
 * **A friend is a mutual follow.** There is no request or accept endpoint here, because
 * there is nothing to request: following someone who already follows you makes you friends,
 * and unfollowing ends it. To add a friend, call `POST /users/{uuid}/follow`; to remove one,
 * `DELETE /users/{uuid}/follow`.
 */
class FriendController extends Controller
{
    public function __construct(protected SocialService $social)
    {
    }

    /** GET /friends */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->social->friends(
            $request->user(),
            (int) ($data['per_page'] ?? 20),
            (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (User $u) => SocialPresenter::user($u) + [
                'last_active_at' => $u->last_active_at?->toIso8601ZuluString(),
            ]
        )->all());
    }
}
