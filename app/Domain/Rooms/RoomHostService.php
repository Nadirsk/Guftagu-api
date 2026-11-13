<?php

namespace App\Domain\Rooms;

use App\Domain\Store\InventoryService;
use App\Events\Rooms\MemberKicked;
use App\Events\Rooms\MemberRoleChanged;
use App\Events\Rooms\RoomAnnouncementUpdated;
use App\Events\Rooms\SeatLockToggled;
use App\Events\Rooms\SeatOccupied;
use App\Events\Rooms\SeatOwnerMuteToggled;
use App\Events\Rooms\SeatVacated;
use App\Events\Rooms\RoomUpdated;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\RoomSeat;
use App\Models\RoomSeatTemplate;
use App\Models\RoomTheme;
use App\Models\User;
use App\Models\UserSanction;
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
    /**
     * Ends a live video. The owner only; a voice room is a standing room and is never ended
     * this way. Everyone is moved out, the seats emptied, and the room closed so it drops
     * off the Live tab and a late joiner is told it has ended.
     *
     * @return array{audience: int, diamonds: int, duration_seconds: int}
     *
     * @throws RoomException
     */
    public function endLive(Room $room, User $actor): array
    {
        $this->assertIsOwner($room, $actor);

        if (! $room->isVideo()) {
            throw new RoomException('BAD_REQUEST', 'Only a live video can be ended.', 400);
        }

        if (! $room->isClosed()) {
            app(RoomCallService::class)->endAllFor($room);
            DB::transaction(function () use ($room) {
                RoomMember::query()
                    ->where('room_id', $room->id)
                    ->where('is_active', true)
                    ->update([
                        'is_active'        => false,
                        'left_at'          => now(),
                        'duration_seconds' => DB::raw('TIMESTAMPDIFF(SECOND, joined_at, NOW())'),
                    ]);

                $room->seats()->update(['user_id' => null, 'occupied_at' => null, 'is_camera_on' => false]);

                $room->forceFill([
                    'status'         => Room::CLOSED,
                    'closed_by'      => null,
                    'close_reason'   => 'ended_by_host',
                    'ended_at'       => now(),
                    'listener_count' => 0,
                    'is_featured'    => false,
                    'is_pinned'      => false,
                ])->save();
            });

            try {
                RoomUpdated::dispatch($room->fresh('theme'), RoomUpdated::CLOSED);
            } catch (\Throwable $e) {
                // The close has committed; viewers also find out from their next state poll.
                report($e);
            }
        }

        $room->refresh();

        return [
            // Distinct people who were ever in the room, the owner not counted.
            'audience'         => RoomMember::query()->where('room_id', $room->id)->where('user_id', '!=', $actor->id)->count(),
            'diamonds'         => (int) $room->total_diamonds_received,
            'duration_seconds' => (int) ($room->started_at && $room->ended_at ? $room->started_at->diffInSeconds($room->ended_at) : 0),
        ];
    }

    /** @throws RoomException */
    public function promoteCoHost(Room $room, User $actor, User $target): RoomMember
    {
        $this->assertIsOwner($room, $actor);

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
        $this->assertIsOwner($room, $actor);

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

        $placedBy = $this->actorRole($room, $actor);

        return DB::transaction(function () use ($room, $target, $seatNumber, $placedBy) {
            $seat = $room->seats()->where('seat_number', $seatNumber)->lockForUpdate()->first();

            if ($seat === null) {
                throw new RoomException('NOT_FOUND', 'That seat does not exist in this room.', 404);
            }

            // A VIP seat takes VIP members only — the owner or admin is told why, rather than
            // Mazag's silent nothing.
            if ($seat->is_vip && app(InventoryService::class)->activeVip($target) === null) {
                throw new RoomException('VIP_SEAT', 'This mic slot is for VIPs only.', 403);
            }

            // "Take on the Mic" on a locked seat: choosing someone for it unlocks it.
            $wasLocked = $seat->is_locked;

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
                // Mazag: someone put on a mic by the owner or an admin starts with it off and is
                // told to turn it on if they want to speak.
                'is_self_muted' => true,
                'is_locked'     => false,
            ])->save();
            $seat->refresh();

            if ($wasLocked) {
                SeatLockToggled::dispatch($room, $seatNumber, false);
            }

            if (! $wasActive) {
                RoomMember::updateOrCreate(
                    ['room_id' => $room->id, 'user_id' => $target->id],
                    ['role' => RoomMember::LISTENER, 'is_active' => true, 'joined_at' => now(), 'left_at' => null, 'duration_seconds' => null],
                );
            }

            SeatOccupied::dispatch($room, $seat, $target, $placedBy);

            return $seat;
        });
    }

    /**
     * "Take on the Mic" → Online listeners: everyone in the room who is not on a mic,
     * newest arrival first, for the owner or an admin to put on a seat.
     *
     * @return \Illuminate\Support\Collection<int, User>
     *
     * @throws RoomException
     */
    public function listeners(Room $room, User $actor): \Illuminate\Support\Collection
    {
        $this->assertIsHost($room, $actor);

        $seated = $room->seats()->whereNotNull('user_id')->pluck('user_id');

        return RoomMember::query()
            ->where('room_id', $room->id)
            ->where('is_active', true)
            ->whereNotIn('user_id', $seated)
            ->where('user_id', '!=', $actor->id)
            ->with('user.profile')
            ->orderByDesc('joined_at')
            ->limit(200)
            ->get()
            ->map(fn (RoomMember $m) => $m->user)
            ->filter()
            ->values();
    }

    /** @throws RoomException */
    public function muteSeat(Room $room, User $actor, int $seatNumber, bool $muted): RoomSeat
    {
        $this->assertIsHost($room, $actor);

        $seat = $room->seats()->where('seat_number', $seatNumber)->first();

        if ($seat === null) {
            throw new RoomException('NOT_FOUND', 'That seat does not exist in this room.', 404);
        }

        // An empty seat can be muted too (Mazag's empty-seat sheet offers it): whoever sits
        // there next starts muted. An admin may lift a mute on their own seat (their seat sheet offers "UnMute the Mic"),
        // but never touch the owner's seat or another admin's.
        $this->assertMayModerate($room, $actor, $seat->user_id);

        $seat->forceFill(['is_muted_by_owner' => $muted])->save();

        SeatOwnerMuteToggled::dispatch($room, $seat);

        return $seat;
    }

    /**
     * Room Mode (Figma 774:7201): switch the room to another seat template. The seats are
     * laid out afresh, so everybody seated — the host too — is stood up; nobody leaves the
     * room. Choosing the mode already in use changes nothing.
     *
     * @throws RoomException
     */
    public function changeMode(Room $room, User $actor, int $templateId): Room
    {
        $this->assertIsHost($room, $actor);

        $template = RoomSeatTemplate::where('id', $templateId)->where('is_active', true)->first();
        if ($template === null) {
            throw new RoomException('NOT_FOUND', 'That room mode does not exist.', 404);
        }

        if ($room->seat_template_id === $template->id) {
            return $room;
        }

        $seated = $room->seats()->whereNotNull('user_id')->with('user')->get();
        $vip = $template->vip_positions ?? [];

        DB::transaction(function () use ($room, $template, $vip) {
            $room->seats()->delete();
            for ($i = 1; $i <= $template->total_seats; $i++) {
                RoomSeat::create(['room_id' => $room->id, 'seat_number' => $i, 'is_vip' => in_array($i, $vip, true)]);
            }
            $room->forceFill(['seat_template_id' => $template->id, 'seat_count' => $template->total_seats])->save();
        });

        // Each person stood up hears it like any other vacated seat (their mic stops), then
        // everyone re-reads the new layout.
        foreach ($seated as $seat) {
            SeatVacated::dispatch($room, $seat->seat_number, $seat->user);
        }
        RoomUpdated::dispatch($room->fresh('theme'), RoomUpdated::MODE);

        return $room->fresh();
    }

    /**
     * Room Theme (Figma 774:8130): a theme from the catalogue, or the host's own picture
     * (the "+" card). Only the background changes — seats and people stay as they are.
     *
     * @throws RoomException
     */
    public function changeTheme(Room $room, User $actor, ?int $themeId, ?string $backgroundUrl): Room
    {
        $this->assertIsHost($room, $actor);

        if ($backgroundUrl !== null) {
            // The host's own upload wins over any theme picture; the theme stays as it was.
            $room->forceFill(['background_url' => $backgroundUrl])->save();
        } else {
            $theme = RoomTheme::where('id', $themeId)->where('is_active', true)->first();
            if ($theme === null) {
                throw new RoomException('NOT_FOUND', 'That room theme does not exist.', 404);
            }
            if ($theme->required_vip_tier_id !== null && ! app(InventoryService::class)->meetsTier($actor, $theme->required_vip_tier_id)) {
                throw new RoomException('FORBIDDEN', 'Your VIP tier is not high enough for this theme.', 403);
            }
            // Picking a theme drops an earlier upload, or the upload would keep hiding it.
            $room->forceFill(['theme_id' => $theme->id, 'background_url' => null])->save();
        }

        RoomUpdated::dispatch($room->fresh('theme'), RoomUpdated::THEME);

        return $room->fresh();
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

        $this->assertMayModerate($room, $actor, $seat->user_id);

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

    /**
     * The room's own Kick-Out: out of the room and its seat, and kept out for `$minutes`.
     *
     * The re-entry block is the same room-scoped `UserSanction` staff kicks write (see
     * {@see RoomModerationService::kick}), so `Room::isBlockedForUser()` enforces both.
     * `issued_by` stays null — that column points at `admin_users`, and a host is not one.
     *
     * @throws RoomException
     */
    public function kickMember(Room $room, User $actor, User $target, int $minutes): void
    {
        $this->putOut($room, $actor, $target, $minutes, block: false);
    }

    /**
     * "Add to block list": out of the room like a kick, but with no end — a room ban with no
     * `expires_at` that only staff (or a later block-list screen) lifts.
     *
     * @throws RoomException
     */
    public function blockMember(Room $room, User $actor, User $target): void
    {
        $this->putOut($room, $actor, $target, null, block: true);
    }

    /**
     * Kick-Out and Block share this: out of the room and off the mic, and kept out — for
     * `$minutes`, or with no end for a block. The re-entry block is the same room-scoped
     * `UserSanction` staff kicks write (see {@see RoomModerationService::kick}), so
     * `Room::isBlockedForUser()` enforces all of them. `issued_by` stays null — that column
     * points at `admin_users`, and a host is not one. Their admin role, if any, is kept.
     *
     * @throws RoomException
     */
    protected function putOut(Room $room, User $actor, User $target, ?int $minutes, bool $block): void
    {
        $this->assertIsHost($room, $actor);

        if ($target->id === $actor->id || $target->id === $room->owner_id) {
            throw new RoomException('BAD_REQUEST', $block ? 'You cannot block that person.' : 'You cannot kick that person out of this room.', 400);
        }

        $this->assertMayModerate($room, $actor, $target->id);

        $member = $this->activeMember($room, $target);

        if ($member === null) {
            throw new RoomException('NOT_FOUND', 'That person is not currently in this room.', 404);
        }

        $by = $this->actorRole($room, $actor);
        $reason = $block
            ? ($by === 'owner' ? RoomKickNotice::REASON_BLOCK_OWNER : RoomKickNotice::REASON_BLOCK_ADMIN)
            : ($by === 'owner' ? RoomKickNotice::REASON_OWNER : RoomKickNotice::REASON_ADMIN);

        $seatNumber = DB::transaction(function () use ($room, $member, $target, $minutes, $reason) {
            $member->forceFill([
                'is_active'        => false,
                'left_at'          => now(),
                'duration_seconds' => $member->joined_at?->diffInSeconds(now()) ?? 0,
                'hand_raised_at'   => null,
            ])->save();

            $seat = $room->seats()->where('user_id', $target->id)->first();
            $seat?->forceFill([
                'user_id'           => null,
                'occupied_at'       => null,
                'is_self_muted'     => false,
                'is_muted_by_owner' => false,
                'is_camera_on'      => false,
            ])->save();

            UserSanction::create([
                'user_id'    => $target->id,
                'type'       => UserSanction::ROOM_BAN,
                'scope'      => 'room',
                'room_id'    => $room->id,
                'reason'     => $reason,
                'starts_at'  => now(),
                'expires_at' => $minutes === null ? null : now()->addMinutes($minutes),
                'is_active'  => true,
            ]);

            return $seat?->seat_number;
        });

        if ($seatNumber !== null) {
            SeatVacated::dispatch($room, $seatNumber, $target);
        }

        // The kicked person's app is still on the room screen: this is what closes it and
        // tells them who did it and for how long. The kick itself has already committed.
        $this->announce(new MemberKicked($room, $target, $by, $minutes, $room->activeMembers()->count(), $block));
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

    /**
     * The owner may act on anyone; an admin on ordinary members and on themselves, never on
     * the owner or another admin (their seat sheet only offers gift, profile and Clear charm).
     *
     * @throws RoomException
     */
    protected function assertMayModerate(Room $room, User $actor, ?int $targetId): void
    {
        if ($targetId === null || $targetId === $actor->id || $room->owner_id === $actor->id) {
            return;
        }

        if ($targetId === $room->owner_id) {
            throw new RoomException('FORBIDDEN', 'You cannot do that to the room owner.', 403);
        }

        $targetIsAdmin = RoomMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $targetId)
            ->where('role', RoomMember::CO_HOST)
            ->exists();

        if ($targetIsAdmin) {
            throw new RoomException('FORBIDDEN', 'Only the room owner can do that to another admin.', 403);
        }
    }

    /** `owner` or `admin` — who the other side's notice names. */
    protected function actorRole(Room $room, User $actor): string
    {
        return $room->owner_id === $actor->id ? 'owner' : 'admin';
    }

    /** Broadcasts after the change has committed; a socket hiccup is reported, not thrown. */
    protected function announce(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Only the owner makes or unmakes admins (co-hosts); an admin cannot appoint more. */
    protected function assertIsOwner(Room $room, User $actor): void
    {
        if ($room->owner_id !== $actor->id) {
            throw new RoomException('FORBIDDEN', 'Only the room owner can change admins.', 403);
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
