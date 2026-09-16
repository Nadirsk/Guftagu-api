<?php

namespace App\Http\Controllers\Api;

use App\Domain\Rooms\RoomParticipationService;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\RoomSeat;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\SocialPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mobile user's own half of a room — join, take/leave a seat, self mic. docs/03 §4.
 *
 * Audio itself never touches this controller: two seated clients open a WebRTC connection
 * directly between themselves once `seat.occupied` tells them a peer showed up, signalling
 * over the `room.{uuid}` presence channel (routes/channels.php). This controller only ever
 * changes and reports *state* — who is seated, who is muted — the same seats and mic level
 * the admin console already sees via `Admin\RoomController::show`.
 */
class RoomController extends Controller
{
    public function __construct(protected RoomParticipationService $participation)
    {
    }

    /** GET /rooms/{room}/state — the single most-called endpoint in the app (docs/03 §194). */
    public function state(Request $request, Room $room): JsonResponse
    {
        return ApiResponse::success($this->snapshot($room, $request->user()));
    }

    /** POST /rooms/{room}/join — `{password?}`. Password-protected rooms are out of scope here. */
    public function join(Request $request, Room $room): JsonResponse
    {
        $this->participation->join($room, $request->user());

        return ApiResponse::success($this->snapshot($room, $request->user()), 'Joined');
    }

    /** POST /rooms/{room}/leave. */
    public function leave(Request $request, Room $room): JsonResponse
    {
        $this->participation->leave($room, $request->user());

        return ApiResponse::success(null, 'Left room');
    }

    /** POST /rooms/{room}/seats/{seat}/take. */
    public function takeSeat(Request $request, Room $room, int $seat): JsonResponse
    {
        $this->participation->takeSeat($room, $request->user(), $seat);

        return ApiResponse::success($this->snapshot($room, $request->user()), 'Seated');
    }

    /** POST /rooms/{room}/seats/leave — vacate whichever seat this user holds. */
    public function leaveSeat(Request $request, Room $room): JsonResponse
    {
        $this->participation->leaveSeat($room, $request->user());

        return ApiResponse::success($this->snapshot($room, $request->user()), 'Seat vacated');
    }

    /** PATCH /rooms/{room}/mic — `{muted: bool}`, self mute/unmute (docs/03 §177). */
    public function setMic(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate(['muted' => ['required', 'boolean']]);

        $this->participation->setMic($room, $request->user(), (bool) $data['muted']);

        return ApiResponse::success($this->snapshot($room, $request->user()), $data['muted'] ? 'Muted' : 'Unmuted');
    }

    /** PATCH /rooms/{room}/camera — `{camera_on: bool}`, self camera on/off (docs/03 §178, D.5b). */
    public function setCamera(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate(['camera_on' => ['required', 'boolean']]);

        $this->participation->setCamera($room, $request->user(), (bool) $data['camera_on']);

        return ApiResponse::success($this->snapshot($room, $request->user()), $data['camera_on'] ? 'Camera on' : 'Camera off');
    }

    // ----------------------------------------------------------------- internals

    protected function snapshot(Room $room, User $viewer): array
    {
        $room->load(['owner.profile:id,user_id,display_name,avatar_url', 'theme:id,background_url']);

        $seats = $room->seats()->with('user.profile:id,user_id,display_name,avatar_url')->orderBy('seat_number')->get();

        $mySeat = $seats->first(fn (RoomSeat $seat) => $seat->user_id === $viewer->id);

        $myMember = RoomMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $viewer->id)
            ->where('is_active', true)
            ->first();

        return [
            'room' => [
                'uuid'            => $room->uuid,
                'name'            => $room->name,
                'status'          => $room->status,
                'listener_count'  => $room->activeMembers()->count(),
                'video_enabled'   => $room->video_enabled,
                'announcement'    => $room->announcement,
                'theme'           => $room->theme === null ? null : ['background_url' => $room->theme->background_url],
            ],
            'owner' => $room->owner === null ? null : SocialPresenter::user($room->owner),
            'seats' => $seats->map(fn (RoomSeat $seat) => [
                'seat_number' => $seat->seat_number,
                'locked'      => $seat->is_locked,
                'is_vip'      => $seat->is_vip,
                'camera_on'   => $seat->is_camera_on,
                'muted'       => $seat->isEffectivelyMuted(),
                'user'        => $seat->user === null ? null : SocialPresenter::user($seat->user),
            ]),
            'my' => [
                'role'          => $myMember?->role,
                'seat_number'   => $mySeat?->seat_number,
                'muted'         => $mySeat?->is_self_muted ?? false,
                'camera_on'     => $mySeat?->is_camera_on ?? false,
                'can_take_seat' => ! $room->isClosed() && ! $room->isBlockedForUser($viewer->id),
            ],
            // No Agora/RTC token block — audio is peer-to-peer WebRTC, signalled entirely
            // over the `room.{uuid}` presence channel this state call tells the client to
            // subscribe to. Any two clients who are both seated connect to each other
            // directly; nothing here mediates the media itself.
            'signaling' => [
                'channel'     => "room.{$room->uuid}",
                'ice_servers' => [['urls' => 'stun:stun.l.google.com:19302']],
            ],
        ];
    }
}
