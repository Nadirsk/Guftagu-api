<?php

namespace App\Http\Controllers\Api;

use App\Domain\Rooms\RoomChatService;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomMessage;
use App\Support\ApiResponse;
use App\Support\Cursor;
use App\Support\SocialPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
