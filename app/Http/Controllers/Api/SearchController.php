<?php

namespace App\Http\Controllers\Api;

use App\Domain\Social\SearchService;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\SearchHistory;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\SocialPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** GFT-222 — search over people and rooms, plus recent searches (epic D.3a). docs/03 §8. */
class SearchController extends Controller
{
    public function __construct(protected SearchService $search)
    {
    }

    /** GET /search?q=&type=users|rooms|all */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'     => ['required', 'string', 'max:100'],
            'type'  => ['sometimes', Rule::in(['users', 'rooms', 'all'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            // The search box calls this on every keystroke; only a submitted search or a
            // tapped result should end up in the history.
            'remember' => ['sometimes', 'boolean'],
        ]);

        $results = $this->search->search(
            $request->user(),
            $data['q'],
            $data['type'] ?? 'all',
            (int) ($data['limit'] ?? 20),
        );

        if ($request->boolean('remember') && $request->user() !== null) {
            $this->search->remember($request->user(), $data['q']);
        }

        return ApiResponse::success([
            'users' => $results['users']->map(fn (User $u) => SocialPresenter::user($u))->all(),
            'rooms' => $results['rooms']->map(fn (Room $r) => [
                'uuid'           => $r->uuid,
                'room_code'      => $r->room_code,
                'name'           => $r->name,
                'cover_url'      => $r->cover_url,
                'status'         => $r->status,
                'listener_count' => (int) $r->listener_count,
                'category'       => $r->category?->name_en,
                'owner'          => SocialPresenter::user($r->owner),
            ])->all(),
        ]);
    }

    /** GET /search/history */
    public function history(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->search->history($request->user())->map(fn (SearchHistory $h) => [
                'uuid'        => $h->uuid,
                'type'        => $h->type,
                'term'        => $h->term,
                'target_uuid' => $h->target_uuid,
                'searched_at' => $h->searched_at?->toIso8601ZuluString(),
            ])->all()
        );
    }

    /** POST /search/history — records a submitted search or a tapped result. */
    public function storeHistory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'term'        => ['required', 'string', 'max:100'],
            'type'        => ['sometimes', Rule::in(SearchHistory::TYPES)],
            'target_uuid' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $entry = $this->search->remember(
            $request->user(),
            $data['term'],
            $data['type'] ?? SearchHistory::TYPE_TERM,
            $data['target_uuid'] ?? null,
        );

        return ApiResponse::success(['uuid' => $entry->uuid], 'Saved', 201);
    }

    /** DELETE /search/history */
    public function clearHistory(Request $request): JsonResponse
    {
        $deleted = $this->search->clearHistory($request->user());

        return ApiResponse::success(['deleted' => $deleted], 'History cleared');
    }

    /** DELETE /search/history/{uuid} */
    public function destroyHistory(Request $request, string $uuid): JsonResponse
    {
        $deleted = $this->search->deleteHistoryEntry($request->user(), $uuid);

        if ($deleted === 0) {
            return ApiResponse::error('NOT_FOUND', 'Resource not found', null, 404);
        }

        return ApiResponse::success(null, 'Removed');
    }
}
