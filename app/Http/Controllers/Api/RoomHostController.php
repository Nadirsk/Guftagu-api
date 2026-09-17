<?php

namespace App\Http\Controllers\Api;

use App\Domain\Rooms\RoomCreationService;
use App\Domain\Rooms\RoomHostService;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * D.2a (create) and D.2b (host/co-host controls on somebody else's seat). Split out of
 * {@see RoomController}, which stays "the mobile user's own half of a room" — nothing in
 * that controller acts on another member, nothing in this one acts on the caller's own seat.
 */
class RoomHostController extends Controller
{
    public function __construct(
        protected RoomCreationService $creation,
        protected RoomHostService $host,
    ) {
    }

    /** POST /rooms — D.2a, "creates a room, room published". */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:80'],
            'description'      => ['sometimes', 'nullable', 'string', 'max:300'],
            'cover_url'        => ['sometimes', 'nullable', 'url', 'max:500'],
            'category_id'      => ['sometimes', 'nullable', 'integer'],
            'theme_id'         => ['sometimes', 'nullable', 'integer'],
            'seat_template_id' => ['sometimes', 'nullable', 'integer'],
            'seat_count'       => ['sometimes', 'integer', 'min:2', 'max:50'],
            'visibility'       => ['sometimes', Rule::in(['public', 'private'])],
            'password'         => ['sometimes', 'nullable', 'string', 'min:4', 'max:100'],
            'video_enabled'    => ['sometimes', 'boolean'],
        ]);

        $room = $this->creation->create($request->user(), $data);

        return ApiResponse::success([
            'uuid'          => $room->uuid,
            'room_code'     => $room->room_code,
            'name'          => $room->name,
            'status'        => $room->status,
            'visibility'    => $room->visibility,
            'seat_count'    => $room->seat_count,
            'video_enabled' => $room->video_enabled,
        ], 'Room published', 201);
    }

    /** POST /rooms/{room}/members/{profile}/co-host */
    public function promoteCoHost(Request $request, Room $room, User $profile): JsonResponse
    {
        $this->host->promoteCoHost($room, $request->user(), $profile);

        return ApiResponse::success(null, 'Co-host granted');
    }

    /** DELETE /rooms/{room}/members/{profile}/co-host */
    public function revokeCoHost(Request $request, Room $room, User $profile): JsonResponse
    {
        $this->host->revokeCoHost($room, $request->user(), $profile);

        return ApiResponse::success(null, 'Co-host revoked');
    }

    /** POST /rooms/{room}/seats/{seat}/invite — `{user_uuid}`. */
    public function inviteToSeat(Request $request, Room $room, int $seat): JsonResponse
    {
        $data = $request->validate(['user_uuid' => ['required', 'string']]);

        $target = User::where('uuid', $data['user_uuid'])->firstOrFail();

        $this->host->inviteToSeat($room, $request->user(), $target, $seat);

        return ApiResponse::success(null, 'Seated');
    }

    /** POST /rooms/{room}/seats/{seat}/mute — `{muted: bool}`, the host's own mute. */
    public function muteSeat(Request $request, Room $room, int $seat): JsonResponse
    {
        $data = $request->validate(['muted' => ['required', 'boolean']]);

        $this->host->muteSeat($room, $request->user(), $seat, (bool) $data['muted']);

        return ApiResponse::success(null, $data['muted'] ? 'Muted' : 'Unmuted');
    }

    /** POST /rooms/{room}/seats/{seat}/remove — frees the seat, does not ban the room. */
    public function removeFromSeat(Request $request, Room $room, int $seat): JsonResponse
    {
        $this->host->removeFromSeat($room, $request->user(), $seat);

        return ApiResponse::success(null, 'Removed from seat');
    }

    /** PATCH /rooms/{room}/seats/{seat}/lock — `{locked: bool}`. */
    public function lockSeat(Request $request, Room $room, int $seat): JsonResponse
    {
        $data = $request->validate(['locked' => ['required', 'boolean']]);

        $this->host->setSeatLocked($room, $request->user(), $seat, (bool) $data['locked']);

        return ApiResponse::success(null, $data['locked'] ? 'Seat locked' : 'Seat unlocked');
    }

    /** PATCH /rooms/{room}/announcement — `{announcement: string|null}`. */
    public function setAnnouncement(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate(['announcement' => ['sometimes', 'nullable', 'string', 'max:500']]);

        $this->host->setAnnouncement($room, $request->user(), $data['announcement'] ?? null);

        return ApiResponse::success(null, 'Announcement updated');
    }
}
