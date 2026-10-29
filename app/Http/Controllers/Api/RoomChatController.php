<?php

namespace App\Http\Controllers\Api;

use App\Domain\Rooms\RoomChatService;
use App\Http\Controllers\Controller;
use App\Events\Rooms\CasualPlayed;
use App\Models\Room;
use App\Models\RoomMessage;
use App\Support\ApiResponse;
use App\Support\Cursor;
use App\Support\SocialPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** D.2d — in-room chat. */
class RoomChatController extends Controller
{
    public function __construct(protected RoomChatService $chat)
    {
    }

    /** GET /rooms/{room}/messages — newest first, cursor-paginated (docs/03 §2.3). */
    public function index(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate([
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->chat->history($room, Cursor::decode($data['cursor'] ?? null), (int) ($data['limit'] ?? 30));

        $items = $page['items']->map(fn (RoomMessage $m) => [
            'uuid'       => $m->uuid,
            'body'       => $m->body,
            'user'       => SocialPresenter::user($m->user),
            'created_at' => $m->created_at?->toIso8601ZuluString(),
        ])->all();

        return ApiResponse::cursor($items, $page['next_cursor'] === null ? null : Cursor::encode($page['next_cursor']));
    }

    /** POST /rooms/{room}/messages — `{body}`. */
    public function store(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:500']]);

        $message = $this->chat->post($room, $request->user(), $data['body']);

        return ApiResponse::success([
            'uuid'       => $message->uuid,
            'body'       => $message->body,
            'created_at' => $message->created_at?->toIso8601ZuluString(),
        ], 'Sent', 201);
    }

    /**
     * POST /rooms/{room}/casual — `{type: dice|mora|machine}`. The server draws the result so
     * nobody can pick their own roll, and a user can play once every two seconds.
     */
    public function casual(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:dice,mora,machine']]);
        $user = $request->user();

        if (! $room->activeMembers()->where('user_id', $user->id)->exists()) {
            return ApiResponse::error('not_in_room', 'Join the room first', null, 403);
        }
        if (! Cache::add("casual:{$room->id}:{$user->id}", 1, 2)) {
            return ApiResponse::error('too_fast', 'Slow down a little', null, 429);
        }

        $result = match ($data['type']) {
            'dice'    => [random_int(1, 6)],
            'mora'    => [random_int(0, 2)],
            'machine' => [random_int(0, 9), random_int(0, 9), random_int(0, 9)],
        };

        try {
            CasualPlayed::dispatch($room, $user, $data['type'], $result);
        } catch (\Throwable $e) {
            report($e);
        }

        return ApiResponse::success(['type' => $data['type'], 'result' => $result]);
    }
}
