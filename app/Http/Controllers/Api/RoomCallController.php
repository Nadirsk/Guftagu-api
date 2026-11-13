<?php

namespace App\Http\Controllers\Api;

use App\Domain\Rooms\RoomCallService;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomCall;
use App\Support\ApiResponse;
use App\Support\SocialPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Private calls in a solo live video: the host's price settings, and the requests viewers make. */
class RoomCallController extends Controller
{
    public function __construct(protected RoomCallService $calls)
    {
    }

    /** GET /rooms/{room}/calls â€” what the host's Private call sheet lists (a viewer sees only their own). */
    public function index(Request $request, Room $room): JsonResponse
    {
        $user = $request->user();
        $list = $this->calls->open($room);

        if ($room->owner_id !== $user->id) {
            $list = $list->where('caller_id', $user->id)->values();
        }

        return ApiResponse::success([
            'prices' => $this->prices($room),
            'calls'  => $list->map(fn (RoomCall $c) => $this->present($c))->all(),
        ]);
    }

    /** PATCH /rooms/{room}/call-settings â€” `{voice_price, video_price, sound}`. */
    public function settings(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate([
            'voice_price' => ['required', 'integer', 'min:0', 'max:1000000'],
            'video_price' => ['required', 'integer', 'min:0', 'max:1000000'],
            'sound'       => ['sometimes', 'boolean'],
        ]);

        $room = $this->calls->saveSettings($room, $request->user(), $data['voice_price'], $data['video_price'], (bool) ($data['sound'] ?? false));

        return ApiResponse::success($this->prices($room), 'Call settings saved');
    }

    /** POST /rooms/{room}/calls â€” `{type: voice|video}`: a viewer asks to call the host. */
    public function store(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:voice,video']]);

        $call = $this->calls->request($room, $request->user(), $data['type']);

        return ApiResponse::success($this->present($call->load('caller.profile')), 'Call requested', 201);
    }

    /** POST /rooms/{room}/calls/{call}/accept â€” the host answers. */
    public function accept(Request $request, Room $room, RoomCall $call): JsonResponse
    {
        return ApiResponse::success($this->present($this->calls->accept($room, $request->user(), $call)->load('caller.profile')), 'Call started');
    }

    /** POST /rooms/{room}/calls/{call}/decline â€” the host turns it down, or the caller cancels. */
    public function decline(Request $request, Room $room, RoomCall $call): JsonResponse
    {
        return ApiResponse::success($this->present($this->calls->decline($room, $request->user(), $call)->load('caller.profile')), 'Request closed');
    }

    /** POST /rooms/{room}/calls/{call}/end â€” hang up. */
    public function end(Request $request, Room $room, RoomCall $call): JsonResponse
    {
        return ApiResponse::success($this->present($this->calls->end($room, $request->user(), $call)->load('caller.profile')), 'Call ended');
    }

    /** @return array<string, mixed> */
    protected function prices(Room $room): array
    {
        return ['voice' => $room->call_voice_price, 'video' => $room->call_video_price, 'sound' => (bool) $room->call_sound];
    }

    /** @return array<string, mixed> */
    protected function present(RoomCall $call): array
    {
        return [
            'id'          => $call->id,
            'type'        => $call->type,
            'price'       => $call->price,
            'status'      => $call->status,
            'seat_number' => $call->seat_number,
            'started_at'  => $call->started_at?->toIso8601String(),
            'caller'      => SocialPresenter::user($call->caller),
        ];
    }
}
