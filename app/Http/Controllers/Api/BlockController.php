<?php

namespace App\Http\Controllers\Api;

use App\Domain\Social\SocialService;
use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\SocialPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GFT-237 — the block list (epic D.9c).
 *
 * D.9c: *"they cannot DM me, call me, see my profile details or send me gifts, and neither
 * of us appears in the other's follower list."* The enforcement is not here — it is in
 * {@see \App\Models\Block::existsBetween()}, which every send, feed and search path
 * consults. This controller only manages the rows.
 */
class BlockController extends Controller
{
    public function __construct(protected SocialService $social)
    {
    }

    /** GET /blocks */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->social->blockList(
            $request->user(),
            (int) ($data['per_page'] ?? 20),
            (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(fn (Block $b) => [
            'user'       => SocialPresenter::user($b->blocked),
            'reason'     => $b->reason,
            'blocked_at' => $b->created_at?->toIso8601ZuluString(),
        ])->all());
    }

    /** POST /users/{uuid}/block */
    public function store(Request $request, User $profile): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $this->social->block($request->user(), $profile, $data['reason'] ?? null);

        return ApiResponse::success([
            'user'       => SocialPresenter::user($profile),
            'is_blocked' => true,
        ], 'Blocked');
    }

    /** DELETE /users/{uuid}/block */
    public function destroy(Request $request, User $profile): JsonResponse
    {
        $this->social->unblock($request->user(), $profile);

        return ApiResponse::success([
            'user'       => SocialPresenter::user($profile),
            'is_blocked' => false,
        ], 'Unblocked');
    }
}
