<?php

namespace App\Domain\Rooms;

use App\Events\Rooms\CameraToggled;
use App\Events\Rooms\HandRaiseToggled;
use App\Events\Rooms\MicToggled;
use App\Events\Rooms\SeatOccupied;
use App\Events\Rooms\SeatVacated;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The user's own half of a room: joining, taking a seat, leaving one, self-muting.
 * docs/03 §4 (`/rooms/{uuid}/join`, `/seats/{n}/take`, `/seats/leave`, `/mic`).
 *
 * Deliberately separate from {@see RoomService} — that one is the admin/moderator surface
 * (audit-logged, actor is an AdminUser). Nothing here is a moderation action, and nothing
 * here writes to audit_logs or moderation_logs.
 */
class RoomParticipationService
{
    /** @throws RoomException */
    public function join(Room $room, User $user, ?string $password = null): RoomMember
    {
        if ($room->isClosed()) {
            throw new RoomException('ROOM_CLOSED', 'This room has ended.', 410);
        }

        if ($room->isBlockedForUser($user->id)) {
            throw new RoomException('FORBIDDEN', 'You cannot rejoin this room right now.', 403);
        }

        // D.2a — "private (password-protected) rooms". The owner and an already-active
        // member never need to re-supply it: re-prompting a host for their own room's
        // password on every reconnect would be a bug, not a feature.
        if (
            $room->visibility === 'private'
            && $room->owner_id !== $user->id
            && ! $this->activeMember($room, $user)
            && (
                $room->password_hash === null
                || $password === null
                || ! Hash::check($password, $room->password_hash)
            )
        ) {
            throw new RoomException('FORBIDDEN', 'That password is not correct.', 403);
        }

        return DB::transaction(function () use ($room, $user) {
            $member = RoomMember::query()
                ->where('room_id', $room->id)
                ->where('user_id', $user->id)
                ->first();

            if ($member === null) {
                return RoomMember::create([
                    'room_id'   => $room->id,
                    'user_id'   => $user->id,
                    'role'      => 'listener',
                    'joined_at' => now(),
                    'is_active' => true,
                ]);
            }

            if (! $member->is_active) {
                $member->forceFill([
                    'is_active'        => true,
                    'joined_at'        => now(),
                    'left_at'          => null,
                    'duration_seconds' => null,
                ])->save();
            }

            return $member;
        });
    }

    public function leave(Room $room, User $user): void
    {
        DB::transaction(function () use ($room, $user) {
            $this->vacateOwnSeat($room, $user);

            RoomMember::query()
                ->where('room_id', $room->id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->update([
                    'is_active'        => false,
                    'left_at'          => now(),
                    'duration_seconds' => DB::raw('TIMESTAMPDIFF(SECOND, joined_at, NOW())'),
                ]);
        });
    }

    /**
     * Sitting elsewhere in the same room vacates wherever this user already was — one seat
     * per person per room, the same invariant `RoomService::setSeatLocked` protects from
     * the admin side.
     *
     * @throws RoomException
     */
    public function takeSeat(Room $room, User $user, int $seatNumber): RoomSeat
    {
        if ($room->isClosed()) {
            throw new RoomException('ROOM_CLOSED', 'This room has ended.', 410);
        }

        if ($room->isBlockedForUser($user->id)) {
            throw new RoomException('FORBIDDEN', 'You cannot use a seat in this room right now.', 403);
        }

        return DB::transaction(function () use ($room, $user, $seatNumber) {
            $seat = $room->seats()->where('seat_number', $seatNumber)->lockForUpdate()->first();

            if ($seat === null) {
                throw new RoomException('NOT_FOUND', 'That seat does not exist in this room.', 404);
            }

            if ($seat->is_locked) {
                throw new RoomException('SEAT_LOCKED', 'That seat is locked.', 409);
            }

            if ($seat->isOccupied() && $seat->user_id !== $user->id) {
                throw new RoomException('SEAT_TAKEN', 'Someone is already sitting there.', 409);
            }

            $this->vacateOwnSeat($room, $user, except: $seatNumber);
            $this->join($room, $user);

            $seat->forceFill([
                'user_id'       => $user->id,
                'occupied_at'   => now(),
                'is_self_muted' => false,
            ])->save();
            $seat->refresh();

            SeatOccupied::dispatch($room, $seat, $user);

            return $seat;
        });
    }

    public function leaveSeat(Room $room, User $user): void
    {
        DB::transaction(fn () => $this->vacateOwnSeat($room, $user));
    }

    /** @throws RoomException */
    public function setMic(Room $room, User $user, bool $muted): RoomSeat
    {
        $seat = $room->seats()->where('user_id', $user->id)->first();

        if ($seat === null) {
            throw new RoomException('NOT_SEATED', 'You are not sitting on a seat in this room.', 409);
        }

        $seat->forceFill(['is_self_muted' => $muted])->save();

        MicToggled::dispatch($room, $seat, $user);

        return $seat;
    }

    /** docs/03 §178 — `PATCH /rooms/{uuid}/camera`, D.5b. @throws RoomException */
    public function setCamera(Room $room, User $user, bool $on): RoomSeat
    {
        $seat = $room->seats()->where('user_id', $user->id)->first();

        if ($seat === null) {
            throw new RoomException('NOT_SEATED', 'You are not sitting on a seat in this room.', 409);
        }

        $seat->forceFill(['is_camera_on' => $on])->save();

        CameraToggled::dispatch($room, $seat, $user);

        return $seat;
    }

    /**
     * D.2c — raise-hand / request-to-speak. Toggled from whatever it currently is, so the
     * client does not need to track state to ask for the opposite of it.
     *
     * @throws RoomException
     */
    public function toggleRaiseHand(Room $room, User $user): RoomMember
    {
        $member = $this->activeMember($room, $user);

        if ($member === null) {
            throw new RoomException('NOT_A_MEMBER', 'You are not in this room.', 409);
        }

        $raised = $member->hand_raised_at === null;

        $member->forceFill(['hand_raised_at' => $raised ? now() : null])->save();

        HandRaiseToggled::dispatch($room, $user, $raised);

        return $member;
    }

    protected function activeMember(Room $room, User $user): ?RoomMember
    {
        return RoomMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Vacates whichever seat this user currently holds in this room, if any. `$except`
     * skips the seat they are about to take, so `takeSeat` can clear an old seat without
     * immediately un-claiming the new one.
     */
    protected function vacateOwnSeat(Room $room, User $user, ?int $except = null): void
    {
        $seat = $room->seats()
            ->where('user_id', $user->id)
            ->when($except !== null, fn ($q) => $q->where('seat_number', '!=', $except))
            ->first();

        if ($seat === null) {
            return;
        }

        $seatNumber = $seat->seat_number;

        $seat->forceFill([
            'user_id'       => null,
            'occupied_at'   => null,
            'is_self_muted' => false,
            'is_camera_on'  => false,
        ])->save();

        SeatVacated::dispatch($room, $seatNumber, $user);
    }
}
