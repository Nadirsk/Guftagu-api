<?php

namespace App\Domain\Rooms;

use App\Domain\Audit\AuditLogger;
use App\Models\AdminUser;
use App\Models\ModerationLog;
use App\Models\Room;
use App\Models\RoomMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GFT-038 / GFT-039 — the admin actions on a room.
 *
 * Force-close is the one that matters. A.4c requires a mandatory reason, every participant
 * disconnected, `status` set to `force_closed`, and the act recorded in **both**
 * `audit_logs` and `moderation_logs` with the admin's identity.
 */
class RoomService
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    /**
     * GFT-039 — close a room by force.
     *
     * @throws RoomException
     */
    public function forceClose(Room $room, string $reason, AdminUser $actor): Room
    {
        if (trim($reason) === '') {
            throw new RoomException('VALIDATION_ERROR', 'A reason is required to close a room.', 422);
        }

        if ($room->isClosed()) {
            throw new RoomException('BAD_REQUEST', 'That room is already closed.', 400);
        }

        $before = [
            'status'         => $room->status,
            'listener_count' => $room->listener_count,
        ];

        $evicted = DB::transaction(function () use ($room, $reason, $actor) {
            // Everyone still in the room is marked gone, with their session closed out —
            // otherwise the presence history would show them there forever.
            $evicted = RoomMember::query()
                ->where('room_id', $room->id)
                ->where('is_active', true)
                ->update([
                    'is_active'        => false,
                    'left_at'          => now(),
                    'duration_seconds' => DB::raw('TIMESTAMPDIFF(SECOND, joined_at, NOW())'),
                ]);

            // Seats are vacated so the durable mirror does not disagree with reality.
            $room->seats()->update([
                'user_id'     => null,
                'occupied_at' => null,
                'is_camera_on' => false,
            ]);

            $room->forceFill([
                'status'         => Room::FORCE_CLOSED,
                'closed_by'      => $actor->id,
                'close_reason'   => trim($reason),
                'ended_at'       => now(),
                'listener_count' => 0,
                // A closed room must not keep occupying a featured slot.
                'is_featured'    => false,
                'is_pinned'      => false,
            ])->save();

            return $evicted;
        });

        $after = ['status' => Room::FORCE_CLOSED, 'evicted' => $evicted, 'reason' => trim($reason)];

        // A.4c wants this in both logs: audit answers "what did staff change", moderation
        // answers "what was done to this room and why".
        $this->audit->log($actor, 'room.force_close', 'rooms', Room::class, $room->id, $before, $after);

        ModerationLog::create([
            'admin_user_id' => $actor->id,
            'action'        => 'room_close',
            'target_type'   => Room::class,
            'target_id'     => (string) $room->id,
            'room_id'       => $room->id,
            'before'        => $before,
            'after'         => $after,
            'reason'        => trim($reason),
            'ip'            => request()->ip(),
        ]);

        return $room->refresh();
    }

    /**
     * GFT-038 — feature or unfeature, optionally with an end time.
     *
     * @throws RoomException
     */
    public function setFeatured(Room $room, bool $featured, ?string $until, AdminUser $actor): Room
    {
        if ($featured && $room->isClosed()) {
            throw new RoomException('BAD_REQUEST', 'A closed room cannot be featured.', 400);
        }

        $expiresAt = null;

        if ($featured && $until !== null && $until !== '') {
            try {
                $expiresAt = Carbon::parse($until);
            } catch (\Throwable) {
                throw new RoomException('VALIDATION_ERROR', 'That is not a valid date.', 422);
            }

            if ($expiresAt->isPast()) {
                throw new RoomException('VALIDATION_ERROR', 'The end of a feature window must be in the future.', 422);
            }
        }

        $before = ['is_featured' => $room->is_featured, 'featured_until' => $room->featured_until?->toIso8601ZuluString()];

        $room->forceFill([
            'is_featured'    => $featured,
            'featured_until' => $featured ? $expiresAt : null,
        ])->save();

        $this->audit->log(
            $actor,
            $featured ? 'room.feature' : 'room.unfeature',
            'rooms',
            Room::class,
            $room->id,
            $before,
            ['is_featured' => $featured, 'featured_until' => $expiresAt?->toIso8601ZuluString()],
        );

        return $room->refresh();
    }

    public function setPinned(Room $room, bool $pinned, AdminUser $actor): Room
    {
        $before = ['is_pinned' => $room->is_pinned];

        $room->forceFill(['is_pinned' => $pinned])->save();

        $this->audit->log(
            $actor,
            $pinned ? 'room.pin' : 'room.unpin',
            'rooms',
            Room::class,
            $room->id,
            $before,
            ['is_pinned' => $pinned],
        );

        return $room->refresh();
    }

    /**
     * @throws RoomException
     */
    public function setCategory(Room $room, int $categoryId, AdminUser $actor): Room
    {
        $before = ['category_id' => $room->category_id];

        $room->forceFill(['category_id' => $categoryId])->save();

        $this->audit->log(
            $actor,
            'room.categorise',
            'rooms',
            Room::class,
            $room->id,
            $before,
            ['category_id' => $categoryId],
        );

        return $room->refresh();
    }

    /** GFT-039 adjacent — lock or unlock a single seat (C.2b). */
    public function setSeatLocked(Room $room, int $seatNumber, bool $locked, AdminUser $actor): void
    {
        $seat = $room->seats()->where('seat_number', $seatNumber)->first();

        if ($seat === null) {
            throw new RoomException('NOT_FOUND', 'That seat does not exist in this room.', 404);
        }

        $before = ['is_locked' => $seat->is_locked, 'user_id' => $seat->user_id];

        $seat->forceFill([
            'is_locked' => $locked,
            // Locking an occupied seat turns the occupant out; leaving them seated on a
            // locked seat is a state the app cannot represent.
            'user_id'      => $locked ? null : $seat->user_id,
            'occupied_at'  => $locked ? null : $seat->occupied_at,
        ])->save();

        ModerationLog::create([
            'admin_user_id' => $actor->id,
            'action'        => $locked ? 'seat_lock' : 'seat_unlock',
            'target_type'   => Room::class,
            'target_id'     => (string) $room->id,
            'room_id'       => $room->id,
            'before'        => $before,
            'after'         => ['is_locked' => $locked, 'seat_number' => $seatNumber],
            'ip'            => request()->ip(),
        ]);
    }
}
