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

    /**
     * The one room a user keeps: their newest room that moderation has not closed.
     * Party → Create My Room opens it instead of the create screen once it exists.
     */
    public static function ownRoom(User $user): ?Room
    {
        return Room::query()
            ->where('owner_id', $user->id)
            ->whereNotIn('status', [Room::CLOSED, Room::FORCE_CLOSED])
            ->latest('id')
            ->first();
    }

    /** GET /rooms/mine — `{room: {uuid, room_code, name, cover_url, status} | null}`. */
    public function mine(Request $request): JsonResponse
    {
        $room = static::ownRoom($request->user());

        return ApiResponse::success(['room' => $room === null ? null : [
            'uuid'      => $room->uuid,
            'room_code' => $room->room_code,
            'name'      => $room->name,
            'cover_url' => $room->cover_url,
            'status'    => $room->status,
        ]]);
    }

    /**
     * POST /rooms — D.2a, "creates a room, room published". A user keeps one room: with
     * one already open this answers 409 ROOM_EXISTS and names it, so the app joins it.
     */
    public function store(Request $request): JsonResponse
    {
        $existing = static::ownRoom($request->user());
        if ($existing !== null) {
            return ApiResponse::error('ROOM_EXISTS', 'You already have a room.', ['uuid' => $existing->uuid], 409);
        }

        $data = $request->validate([
            'name'             => ['required', 'string', 'max:80'],
            'description'      => ['sometimes', 'nullable', 'string', 'max:300'],
            'cover_url'        => ['sometimes', 'nullable', 'url', 'max:500'],
            // Create Room's own upload under Room Background, used instead of a theme's.
            'background_url'   => ['sometimes', 'nullable', 'url', 'max:500'],
            'announcement'     => ['sometimes', 'nullable', 'string', 'max:500'],
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

    /** PATCH /rooms/{room}/mode — `{seat_template_id}`; every seat is emptied. */
    public function changeMode(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate(['seat_template_id' => ['required', 'integer']]);

        $this->host->changeMode($room, $request->user(), (int) $data['seat_template_id']);

        return ApiResponse::success(null, 'Room mode changed');
    }

    /** PATCH /rooms/{room}/theme — `{theme_id}` or `{background_url}`; nobody moves. */
    public function changeTheme(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate([
            'theme_id'       => ['required_without:background_url', 'nullable', 'integer'],
            'background_url' => ['required_without:theme_id', 'nullable', 'url', 'max:500'],
        ]);

        $this->host->changeTheme($room, $request->user(), $data['theme_id'] ?? null, $data['background_url'] ?? null);

        return ApiResponse::success(null, 'Room theme changed');
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

    /** POST /rooms/{room}/members/{profile}/kick — `{minutes}`, out of the room for that long. */
    public function kickMember(Request $request, Room $room, User $profile): JsonResponse
    {
        $data = $request->validate([
            // The app offers 5 min, 1 hr, 24 hr and 7 days; anything up to 7 days is accepted.
            'minutes' => ['required', 'integer', 'min:1', 'max:10080'],
        ]);

        $this->host->kickMember($room, $request->user(), $profile, (int) $data['minutes']);

        return ApiResponse::success(null, 'Kicked out');
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
