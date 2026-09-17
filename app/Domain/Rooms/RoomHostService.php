<?php

namespace App\Domain\Rooms;

use App\Events\Rooms\MemberRoleChanged;
use App\Events\Rooms\RoomAnnouncementUpdated;
use App\Events\Rooms\SeatLockToggled;
use App\Events\Rooms\SeatOccupied;
use App\Events\Rooms\SeatOwnerMuteToggled;
use App\Events\Rooms\SeatVacated;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * D.2b — what a room's owner or co-host may do to *somebody else's* seat. Deliberately
 * separate from {@see RoomParticipationService} (self-actions, no authority check needed)
 * and from {@see RoomModerationService} (staff, `AdminUser`, sanctioned and audited).
 *
 * Nothing here writes an audit or moderation log — a host muting someone in their own
 * room is a normal act of running it, not a platform enforcement action. What it *must*
 * never do is undo one: {@see muteSeat}/{@see removeFromSeat} touch only the columns a
 * host owns (`is_muted_by_owner`) and never `is_muted_by_host` or a `UserSanction`.
 */
class RoomHostService
{
    /** @throws RoomException */
    public function promoteCoHost(Room $room, User $actor, User $target): RoomMember
    {
        $this->assertIsHost($room, $actor);

        $member = $this->activeMember($room, $target);

        if ($member === null) {
            throw new RoomException('NOT_FOUND', 'That person is not currently in this room.', 404);
        }

        if ($member->role === RoomMember::OWNER) {
            throw new RoomException('BAD_REQUEST', 'The owner is already the owner.', 400);
        }

        $member->forceFill(['role' => RoomMember::CO_HOST])->save();

        MemberRoleChanged::dispatch($room, $target, RoomMember::CO_HOST);

        return $member;
    }

    /** @throws RoomException */
    public function revokeCoHost(Room $room, User $actor, User $target): RoomMember
    {
        $this->assertIsHost($room, $actor);

        $member = $this->activeMember($room, $target);

        if ($member === null || $member->role !== RoomMember::CO_HOST) {
            throw new RoomException('BAD_REQUEST', 'That person is not a co-host.', 400);
        }

        $member->forceFill(['role' => RoomMember::LISTENER])->save();

        MemberRoleChanged::dispatch($room, $target, RoomMember::LISTENER);

        return $member;
    }

    /** @throws RoomException */
    public function inviteToSeat(Room $room, User $actor, User $target, int $seatNumber): RoomSeat
    {
        $this->assertIsHost($room, $actor);

        if ($room->isClosed()) {
            throw new RoomException('ROOM_CLOSED', 'This room has ended.', 410);
        }

        if ($room->isBlockedForUser($target->id)) {
            throw new RoomException('FORBIDDEN', 'That person cannot be seated in this room right now.', 403);
        }

        return DB::transaction(function () use ($room, $target, $seatNumber) {
            $seat = $room->seats()->where('seat_number', $seatNumber)->lockForUpdate()->first();

            if ($seat === null) {
                throw new RoomException('NOT_FOUND', 'That seat does not exist in this room.', 404);
            }

            if ($seat->is_locked) {
                throw new RoomException('SEAT_LOCKED', 'That seat is locked — unlock it first.', 409);
            }

            if ($seat->isOccupied() && $seat->user_id !== $target->id) {
                throw new RoomException('SEAT_TAKEN', 'Someone is already sitting there.', 409);
            }

            // One seat per person: clear anywhere else they were sitting in this room.
            $room->seats()->where('user_id', $target->id)->where('seat_number', '!=', $seatNumber)
                ->update(['user_id' => null, 'occupied_at' => null, 'is_self_muted' => false, 'is_camera_on' => false]);

            $wasActive = $this->activeMember($room, $target) !== null;

            $seat->forceFill([
                'user_id'       => $target->id,
                'occupied_at'   => now(),
                'is_self_muted' => false,
            ])->save();
            $seat->refresh();

            if (! $wasActive) {
                RoomMember::updateOrCreate(
                    ['room_id' => $room->id, 'user_id' => $target->id],
                    ['role' => RoomMember::LISTENER, 'is_active' => true, 'joined_at' => now(), 'left_at' => null, 'duration_seconds' => null],
                );
            }

            SeatOccupied::dispatch($room, $seat, $target);

            return $seat;
        });
    }

    /** @throws RoomException */
    public function muteSeat(Room $room, User $actor, int $seatNumber, bool $muted): RoomSeat
    {
        $this->assertIsHost($room, $actor);

        $seat = $room->seats()->where('seat_number', $seatNumber)->first();

        if ($seat === null) {
            throw new RoomException('NOT_FOUND', 'That seat does not exist in this room.', 404);
        }

        if (! $seat->isOccupied()) {
            throw new RoomException('BAD_REQUEST', 'That seat is empty. There is nobody to mute.', 400);
        }

        $seat->forceFill(['is_muted_by_owner' => $muted])->save();

        SeatOwnerMuteToggled::dispatch($room, $seat);

        return $seat;
    }

    /** Frees the seat without banning the occupant from the room — see {@see RoomModerationService::kick} for that. */
    public function removeFromSeat(Room $room, User $actor, int $seatNumber): void
    {
        $this->assertIsHost($room, $actor);

        $seat = $room->seats()->where('seat_number', $seatNumber)->first();

        if ($seat === null) {
            throw new RoomException('NOT_FOUND', 'That seat does not exist in this room.', 404);
        }

        if (! $seat->isOccupied()) {
            return;
        }

        $user = $seat->user;

        $seat->forceFill([
            'user_id'           => null,
            'occupied_at'       => null,
            'is_self_muted'     => false,
            'is_muted_by_owner' => false,
            'is_camera_on'      => false,
        ])->save();

        SeatVacated::dispatch($room, $seatNumber, $user);
    }

    /** @throws RoomException */
    public function setSeatLocked(Room $room, User $actor, int $seatNumber, bool $locked): void
    {
        $this->assertIsHost($room, $actor);

        $seat = $room->seats()->where('seat_number', $seatNumber)->first();

        if ($seat === null) {
            throw new RoomException('NOT_FOUND', 'That seat does not exist in this room.', 404);
        }

        $seat->forceFill([
            'is_locked'    => $locked,
            // Mirrors the admin side (RoomService::setSeatLocked): a locked seat cannot
            // also be occupied, so locking one turns its occupant out.
            'user_id'      => $locked ? null : $seat->user_id,
            'occupied_at'  => $locked ? null : $seat->occupied_at,
        ])->save();

        SeatLockToggled::dispatch($room, $seatNumber, $locked);
    }

    /** @throws RoomException */
    public function setAnnouncement(Room $room, User $actor, ?string $announcement): Room
    {
        $this->assertIsHost($room, $actor);

        $room->forceFill(['announcement' => $announcement === null ? null : trim($announcement)])->save();

        RoomAnnouncementUpdated::dispatch($room);

        return $room;
    }

    /** @throws RoomException */
    protected function assertIsHost(Room $room, User $actor): void
    {
        if ($room->owner_id === $actor->id) {
            return;
        }

        $isCoHost = RoomMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $actor->id)
            ->where('role', RoomMember::CO_HOST)
            ->where('is_active', true)
            ->exists();

        if (! $isCoHost) {
            throw new RoomException('FORBIDDEN', 'Only the host or a co-host can do that.', 403);
        }
    }

    protected function activeMember(Room $room, User $user): ?RoomMember
    {
        return RoomMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();
    }
}
