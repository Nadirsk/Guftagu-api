<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Services\ScopeFilter;
use App\Domain\Audit\AuditLogger;
use App\Domain\Rooms\RoomModerationService;
use App\Domain\Rooms\RoomService;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\RoomSeat;
use App\Models\User;
use App\Models\UserSanction;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Epic A.4 — room monitoring and enforcement. docs/03 §10.
 *
 * `listener_count` is denormalised from Redis every 10 s by the realtime layer, so these
 * figures are recent rather than live. Until that layer exists (E.1), they are whatever
 * was last written to MySQL — the response says so via `realtime`.
 */
class RoomController extends Controller
{
    protected const SORTABLE = ['id', 'name', 'listener_count', 'started_at', 'created_at'];

    public function __construct(
        protected RoomService $rooms,
        protected AuditLogger $audit,
        protected ScopeFilter $scope,
        protected RoomModerationService $moderation,
    ) {
    }

    /** GET /admin/rooms — GFT-036. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'         => ['sometimes', 'nullable', 'string', 'max:100'],
            'status'    => ['sometimes', 'nullable', Rule::in(['live', 'idle', 'closed', 'force_closed'])],
            'category'  => ['sometimes', 'nullable', 'integer'],
            'featured'  => ['sometimes', 'boolean'],
            'min_seats' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'page'      => ['sometimes', 'integer', 'min:1'],
            'per_page'  => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort'      => ['sometimes', 'string', 'max:40'],
        ]);

        [$column, $direction] = $this->parseSort($data['sort'] ?? '-listener_count');

        $query = Room::query()
            ->with(['owner.profile:id,user_id,display_name', 'category:id,key,name_en'])
            ->search($data['q'] ?? null)
            ->when($data['status'] ?? null, fn ($q, string $s) => $q->where('status', $s))
            ->when($data['category'] ?? null, fn ($q, int $c) => $q->where('category_id', $c))
            ->when($request->boolean('featured'), fn ($q) => $q->featured())
            ->when($data['min_seats'] ?? null, fn ($q, int $n) => $q->where('seat_count', '>=', $n))
            ->orderByDesc('is_pinned')
            ->orderBy($column, $direction);

        // C.1a — "shows only rooms within their granted category scope". Applied in SQL,
        // so the count and the pagination agree with the rows shown.
        $this->scope->applyRoomCategory($query, $request->user(), 'rooms.category_id');

        $paginator = $query->paginate(
            perPage: (int) ($data['per_page'] ?? 24),
            page: (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (Room $room) => $this->rowPayload($room)
        )->all());
    }

    /** GET /admin/rooms/live — A.4a. The monitoring view, which only ever shows live rooms. */
    public function live(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['sometimes', 'nullable', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $rooms = Room::query()
            ->with(['owner.profile:id,user_id,display_name', 'category:id,key,name_en'])
            ->live()
            ->when($data['category'] ?? null, fn ($q, int $c) => $q->where('category_id', $c))
            ->orderByDesc('is_pinned')
            ->orderByDesc('listener_count')
            ->limit((int) ($data['per_page'] ?? 48))
            ->get();

        return ApiResponse::success([
            'rooms'     => $rooms->map(fn (Room $room) => $this->rowPayload($room)),
            'total'     => $rooms->count(),
            'listeners' => (int) $rooms->sum('listener_count'),
            // Honest about where these numbers come from.
            'realtime'  => [
                'available' => false,
                'source'    => 'database',
                'note'      => 'Counts are the last value written to MySQL. Live Redis-backed figures arrive with the realtime layer (E.1).',
            ],
            'as_of' => now()->toIso8601ZuluString(),
        ]);
    }

    /** GET /admin/rooms/{room} — GFT-037. */
    public function show(Request $request, Room $room): JsonResponse
    {
        // C.1a's scope also governs the direct-id path, not only the list.
        $this->scope->guardRoomCategory($request->user(), $room->category_id, 'room');

        $room->load([
            'owner.profile:id,user_id,display_name,avatar_url',
            'category:id,key,name_en',
            'theme:id,name,is_premium',
            'closedBy:id,name',
            'seats.user.profile:id,user_id,display_name,avatar_url',
        ]);

        $members = RoomMember::query()
            ->with('user.profile:id,user_id,display_name')
            ->where('room_id', $room->id)
            ->where('is_active', true)
            ->orderBy('joined_at')
            ->limit(100)
            ->get();

        // C.1c, rounded out: prior sanctions in *this* room, so a moderator can see
        // whether an occupant has already been warned or muted here before deciding.
        $priorSanctions = UserSanction::query()
            ->where('room_id', $room->id)
            ->whereIn('user_id', $members->pluck('user_id')->merge($room->seats->pluck('user_id'))->filter()->unique())
            ->orderByDesc('id')
            ->get()
            ->groupBy('user_id');

        return ApiResponse::success([
            'room'  => $this->rowPayload($room),
            'seats' => $room->seats->map(fn ($seat) => [
                'seat_number'      => $seat->seat_number,
                'is_locked'        => $seat->is_locked,
                'is_muted_by_host' => $seat->is_muted_by_host,
                'is_camera_on'     => $seat->is_camera_on,
                'occupied_at'      => $seat->occupied_at?->toIso8601ZuluString(),
                'user'             => $seat->user === null ? null : [
                    'id'           => $seat->user->id,
                    'guftagu_id'   => $seat->user->guftagu_id,
                    'display_name' => $seat->user->profile?->display_name,
                    'avatar_url'   => $seat->user->profile?->avatar_url,
                    'prior_sanctions_here' => $priorSanctions->get($seat->user->id, collect())->count(),
                ],
            ]),
            'members' => $members->map(fn (RoomMember $member) => [
                'user_id'      => $member->user_id,
                'display_name' => $member->user?->profile?->display_name,
                'guftagu_id'   => $member->user?->guftagu_id,
                'role'         => $member->role,
                'joined_at'    => $member->joined_at?->toIso8601ZuluString(),
                'prior_sanctions_here' => $priorSanctions->get($member->user_id, collect())->count(),
            ]),
            'closure' => $room->isClosed() ? [
                'status'    => $room->status,
                'reason'    => $room->close_reason,
                'closed_by' => $room->closedBy?->name,
                'ended_at'  => $room->ended_at?->toIso8601ZuluString(),
            ] : null,
            // Chat and gift volume need D.2d and the gifting module.
            'pending' => ['chat' => true, 'gifts' => true],
        ]);
    }

    /** POST /admin/rooms/{room}/close — A.4c. */
    public function close(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $closed = $this->rooms->forceClose($room, $data['reason'], $request->user());

        return ApiResponse::success([
            'status'   => $closed->status,
            'ended_at' => $closed->ended_at?->toIso8601ZuluString(),
            // The DB state is authoritative now; pushing everyone out of the Agora channel
            // needs the realtime layer, so say so rather than implying it happened.
            'broadcast' => [
                'sent' => false,
                'note' => 'Participants are marked out and seats vacated. Disconnecting live clients needs the realtime layer (E.1).',
            ],
        ], 'Room force-closed');
    }

    /** POST /admin/rooms/{room}/feature — A.4b. */
    /**
     * POST /admin/rooms/feature-bulk — B.3c.
     *
     * > "Given 5 rooms selected within scope, when the Manager runs a promotion, then all
     * > 5 are featured for the chosen window and the action is audit-logged."
     *
     * All five or none. A promotion that features three rooms and fails on the fourth
     * leaves a half-run campaign that somebody has to reconstruct by hand, so the whole
     * set is validated first and applied in one transaction.
     */
    public function featureBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_ids'   => ['required', 'array', 'min:1', 'max:100'],
            'room_ids.*' => ['integer', Rule::exists('rooms', 'id')],
            'until'      => ['required', 'date', 'after:now'],
        ]);

        $rooms = Room::query()->whereIn('id', $data['room_ids'])->get();

        if ($rooms->count() !== count(array_unique($data['room_ids']))) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Some of those rooms no longer exist. Reselect and try again.',
                null,
                422,
            );
        }

        // Category scope, checked on every room before anything is written.
        foreach ($rooms as $room) {
            $this->scope->guardRoomCategory($request->user(), $room->category_id, 'room');
        }

        $until = Carbon::parse($data['until']);

        DB::transaction(function () use ($rooms, $until, $request) {
            foreach ($rooms as $room) {
                $this->rooms->setFeatured($room, true, $until->toIso8601String(), $request->user());
            }
        });

        // One audit row for the promotion as a whole, on top of the per-room rows the
        // service writes: "who ran this campaign" is a different question from "why is
        // this room featured", and both get asked.
        $this->audit->log($request->user(), 'rooms.feature_bulk', 'rooms', Room::class, null, null, [
            'room_ids' => $rooms->pluck('id')->all(),
            'until'    => $until->toIso8601ZuluString(),
        ]);

        return ApiResponse::success([
            'featured' => $rooms->count(),
            'until'    => $until->toIso8601ZuluString(),
            // The window is derived at read time, so nothing has to un-feature them.
            'note' => 'They stop being featured when the window passes — no follow-up needed.',
        ], sprintf('%d rooms featured', $rooms->count()));
    }

    public function feature(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate([
            'featured' => ['required', 'boolean'],
            'until'    => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        $updated = $this->rooms->setFeatured(
            $room,
            (bool) $data['featured'],
            $data['until'] ?? null,
            $request->user(),
        );

        return ApiResponse::success([
            'is_featured'    => $updated->is_featured,
            'featured_until' => $updated->featured_until?->toIso8601ZuluString(),
        ], $updated->is_featured ? 'Room featured' : 'Room unfeatured');
    }

    /** POST /admin/rooms/{room}/pin. */
    public function pin(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate(['pinned' => ['required', 'boolean']]);

        $updated = $this->rooms->setPinned($room, (bool) $data['pinned'], $request->user());

        return ApiResponse::success(
            ['is_pinned' => $updated->is_pinned],
            $updated->is_pinned ? 'Room pinned' : 'Room unpinned',
        );
    }

    /** PATCH /admin/rooms/{room}/category. */
    public function categorise(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('room_categories', 'id')],
        ]);

        $this->rooms->setCategory($room, (int) $data['category_id'], $request->user());

        return ApiResponse::success(null, 'Category changed');
    }

    /** POST /admin/rooms/{room}/seats/{seat}/lock — C.2b. */
    public function lockSeat(Request $request, Room $room, int $seat): JsonResponse
    {
        $data = $request->validate(['locked' => ['required', 'boolean']]);

        $this->rooms->setSeatLocked($room, $seat, (bool) $data['locked'], $request->user());

        return ApiResponse::success(null, $data['locked'] ? 'Seat locked' : 'Seat unlocked');
    }

    /** POST /admin/rooms/{room}/silent-join — C.1b. */
    public function silentJoin(Request $request, Room $room): JsonResponse
    {
        $this->scope->guardRoomCategory($request->user(), $room->category_id, 'room');

        return ApiResponse::success($this->moderation->silentJoin($room, $request->user()), 'Logged');
    }

    /** POST /admin/rooms/{room}/seats/{seat}/mute — C.2a. */
    public function muteSeat(Request $request, Room $room, int $seat): JsonResponse
    {
        $this->scope->guardRoomCategory($request->user(), $room->category_id, 'room');

        $data = $request->validate([
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
            'reason'           => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $seatModel = $room->seats()->where('seat_number', $seat)->firstOrFail();

        $this->moderation->mute($room, $seatModel, $data['duration_minutes'] ?? null, $data['reason'], $request->user());

        return ApiResponse::success(null, 'Muted');
    }

    /** POST /admin/rooms/{room}/seats/{seat}/unmute. */
    public function unmuteSeat(Request $request, Room $room, int $seat): JsonResponse
    {
        $this->scope->guardRoomCategory($request->user(), $room->category_id, 'room');

        $seatModel = $room->seats()->where('seat_number', $seat)->firstOrFail();

        $this->moderation->unmute($room, $seatModel, $request->user());

        return ApiResponse::success(null, 'Unmuted');
    }

    /** POST /admin/rooms/{room}/members/{user}/kick — C.2b. */
    public function kickMember(Request $request, Room $room, User $user): JsonResponse
    {
        $this->scope->guardRoomCategory($request->user(), $room->category_id, 'room');

        $data = $request->validate([
            'reentry_block_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:525600'],
            'reason'                => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->moderation->kick($room, $user, $data['reentry_block_minutes'] ?? null, $data['reason'], $request->user());

        return ApiResponse::success(null, 'Removed from room');
    }

    /** POST /admin/rooms/{room}/warn — C.2c. */
    public function warnMember(Request $request, Room $room): JsonResponse
    {
        $this->scope->guardRoomCategory($request->user(), $room->category_id, 'room');

        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'message' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $result = $this->moderation->warn($room, User::findOrFail($data['user_id']), $data['message'], $request->user());

        return ApiResponse::success([
            'chat_posted' => $result['chat_posted'],
            'note' => 'Sent as an in-app notification. The system message in the room\'s live chat cannot be written yet — there is no messages table until D.4.',
        ], 'Warning issued');
    }

    // ----------------------------------------------------------------- internals

    protected function parseSort(string $sort): array
    {
        $descending = str_starts_with($sort, '-');
        $column = ltrim($sort, '-');

        if (! in_array($column, self::SORTABLE, true)) {
            return ['listener_count', 'desc'];
        }

        return [$column, $descending ? 'desc' : 'asc'];
    }

    protected function rowPayload(Room $room): array
    {
        return [
            'id'          => $room->id,
            'uuid'        => $room->uuid,
            'room_code'   => $room->room_code,
            'name'        => $room->name,
            'description' => $room->description,
            'cover_url'   => $room->cover_url,
            'visibility'  => $room->visibility,
            'status'      => $room->status,
            'category'    => $room->category === null ? null : [
                'id'   => $room->category->id,
                'key'  => $room->category->key,
                'name' => $room->category->name_en,
            ],
            'owner' => $room->owner === null ? null : [
                'id'           => $room->owner->id,
                'guftagu_id'   => $room->owner->guftagu_id,
                'display_name' => $room->owner->profile?->display_name,
            ],
            'seat_count'      => $room->seat_count,
            'seat_layout'     => $room->seat_layout,
            'video_enabled'   => $room->video_enabled,
            'listener_count'  => $room->listener_count,
            'peak_listeners'  => $room->peak_listeners,
            'diamonds'        => $room->total_diamonds_received,
            'is_pinned'       => $room->is_pinned,
            // The stored flag and the effective state differ once a window lapses, and the
            // panel needs the effective one to avoid showing an expired badge.
            'is_featured'     => $room->isCurrentlyFeatured(),
            'featured_flag'   => $room->is_featured,
            'featured_until'  => $room->featured_until?->toIso8601ZuluString(),
            'started_at'      => $room->started_at?->toIso8601ZuluString(),
            'created_at'      => $room->created_at?->toIso8601ZuluString(),
        ];
    }
}
