<?php

namespace App\Http\Controllers\Api;

use App\Domain\Social\SocialService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserVisit;
use App\Support\ApiResponse;
use App\Support\SocialPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GFT-227 — profile visitors. docs/03 §8: `GET /users/{uuid}/visitors`.
 *
 * Recording a visit is its own POST rather than a side effect of `GET /users/{uuid}`.
 * A GET that writes cannot be retried, prefetched or cached, and every client library does
 * at least one of those.
 */
class VisitorController extends Controller
{
    public function __construct(protected SocialService $social)
    {
    }

    /** POST /users/{uuid}/visit */
    public function store(Request $request, User $profile): JsonResponse
    {
        $isNew = $this->social->recordVisit($request->user(), $profile);

        return ApiResponse::success(['is_new_visitor' => $isNew], 'Visit recorded');
    }

    /**
     * GET /users/{uuid}/visitors
     *
     * Your own list only. Someone else's visitor list says who has been looking at them,
     * which is theirs to know and nobody else's.
     */
    public function index(Request $request, User $profile): JsonResponse
    {
        if ($profile->id !== $request->user()->id) {
            return ApiResponse::error('FORBIDDEN', 'You can only see your own visitors.', null, 403);
        }

        $data = $request->validate([
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->social->visitors(
            $profile,
            (int) ($data['per_page'] ?? 20),
            (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(fn (UserVisit $v) => [
            'user'        => SocialPresenter::user($v->visitor),
            'visit_count' => (int) $v->visit_count,
            'visited_at'  => $v->visited_at?->toIso8601ZuluString(),
        ])->all());
    }
}
