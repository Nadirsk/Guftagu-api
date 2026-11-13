<?php

namespace App\Domain\Rooms;

use App\Domain\Store\InventoryService;
use App\Events\Rooms\CameraToggled;
use App\Events\Rooms\EntranceEffectPlayed;
use App\Events\Rooms\HandRaiseToggled;
use App\Events\Rooms\MemberJoined;
use App\Events\Rooms\MemberLeft;
use App\Events\Rooms\MicToggled;
use App\Events\Rooms\SeatOccupied;
use App\Events\Rooms\SeatVacated;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\RoomSeat;
use App\Models\User;
use App\Models\UserSanction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
    /**
     * How long a member's heartbeat (the app's `/state` poll, every ~10 s while it is open) may
     * stay quiet before they count as gone: app closed, phone locked, or the app left in the
     * background. Voice rooms behave the same way — closing the app takes you off the mic.
     */
    public const STALE_SECONDS = 90;

    public function __construct(protected InventoryService $inventory)
    {
    }

    /**
     * Takes everyone whose heartbeat has gone quiet out of their room — member row closed, seat
     * vacated, everybody told — exactly as if they had tapped Leave. Returns how many left.
     *
     * Nothing tells the server an app was killed, so absence is the signal. Run from the
     * scheduler every minute, and lazily (see {@see evictStaleThrottled}) so it still holds
     * where the scheduler is not running.
     */
    public function evictStale(): int
    {
        $cutoff = now()->subSeconds(self::STALE_SECONDS);

        $stale = RoomMember::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->where('last_seen_at', '<', $cutoff)
                ->orWhere(fn ($q) => $q->whereNull('last_seen_at')->where('joined_at', '<', $cutoff)))
            ->with(['room', 'user'])
            ->get();

        $evicted = 0;

        foreach ($stale as $member) {
            if ($member->room === null || $member->user === null) {
                continue;
            }

            try {
                // A live video is its host: when the host goes quiet the live is over, for everyone watching.
                if ($member->room->isVideo() && ! $member->room->isClosed() && $member->room->owner_id === $member->user_id) {
                    app(RoomHostService::class)->endLive($member->room, $member->user);
                    $evicted++;

                    continue;
                }

                $this->leave($member->room, $member->user);
                $evicted++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $evicted;
    }

    /** {@see evictStale}, at most once every ten seconds across the whole app. */
    public function evictStaleThrottled(): void
    {
        if (! Cache::add('rooms:evict-stale', 1, 10)) {
            return;
        }

        try {
            $this->evictStale();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @throws RoomException */
    public function join(Room $room, User $user, ?string $password = null): RoomMember
    {
        if ($room->isClosed()) {
            throw new RoomException('ROOM_CLOSED', 'This room has ended.', 410);
        }

        if ($room->isBlockedForUser($user->id)) {
            throw new RoomException('FORBIDDEN', $this->blockedMessage($room, $user), 403);
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

        // One room at a time: being let into this one takes you out of any other room you are
        // still in — and off the seat you held there.
        $this->leaveOtherRooms($room, $user);

        $arrived = false;

        $member = DB::transaction(function () use ($room, $user, &$arrived) {
            $member = RoomMember::query()
                ->where('room_id', $room->id)
                ->where('user_id', $user->id)
                ->first();

            if ($member === null) {
                $arrived = true;

                return RoomMember::create([
                    'room_id'   => $room->id,
                    'user_id'   => $user->id,
                    'role'      => 'listener',
                    'joined_at' => now(),
                    'last_seen_at' => now(),
                    'is_active' => true,
                ]);
            }

            // Still marked inside, but the heartbeat went quiet (app closed, phone locked, left
            // minimised): they are really gone, so this is a fresh arrival — a new join time,
            // which is what keeps the chat from their absence out of their view.
            $stale = $member->is_active
                && ($member->last_seen_at === null ? $member->joined_at?->lt(now()->subSeconds(self::STALE_SECONDS)) : $member->last_seen_at->lt(now()->subSeconds(self::STALE_SECONDS)));

            if (! $member->is_active || $stale) {
                $arrived = true;
                $member->forceFill([
                    'is_active'        => true,
                    'joined_at'        => now(),
                    'last_seen_at'     => now(),
                    'left_at'          => null,
                    'duration_seconds' => null,
                ])->save();
            }

            return $member;
        });

        // A reconnect by someone already inside announces nothing.
        if ($arrived) {
            $this->announce(new MemberJoined($room, $user, $room->activeMembers()->count()));

            $arrival = $this->inventory->arrival($user);
            if ($arrival !== null) {
                $this->announce(new EntranceEffectPlayed($room, $user, $arrival));
            }
        }

        return $member;
    }

    /**
     * Takes `$user` out of every room except `$keep` (member row closed, seat vacated, everybody
     * told) — whichever way they got into two: a second device, a room left minimised, a closed app.
     */
    protected function leaveOtherRooms(Room $keep, User $user): void
    {
        $roomIds = RoomMember::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('room_id', '!=', $keep->id)
            ->pluck('room_id')
            ->merge(
                RoomSeat::query()->where('user_id', $user->id)->where('room_id', '!=', $keep->id)->pluck('room_id')
            )
            ->unique();

        foreach (Room::query()->whereIn('id', $roomIds)->get() as $other) {
            $this->leave($other, $user);
        }
    }

    public function leave(Room $room, User $user): void
    {
        $left = DB::transaction(function () use ($room, $user) {
            $this->vacateOwnSeat($room, $user);

            return RoomMember::query()
                ->where('room_id', $room->id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->update([
                    'is_active'        => false,
                    'left_at'          => now(),
                    'duration_seconds' => DB::raw('TIMESTAMPDIFF(SECOND, joined_at, NOW())'),
                ]);
        });

        if ($left > 0) {
            $this->announce(new MemberLeft($room, $user, $room->activeMembers()->count()));
        }
    }

    /**
     * Arrival and departure broadcasts are decoration — the join or leave has already
     * committed. Unlike the seat events (which other peers' WebRTC depends on), a socket
     * hiccup here is reported and swallowed rather than failing the request.
     */
    protected function announce(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            report($e);
        }
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

        // A solo live video has one stage and it is the host's; everyone else only watches.
        if ($room->kind === Room::KIND_VIDEO && $room->owner_id !== $user->id) {
            throw new RoomException('NO_SEATS', 'This live has no seats - you can only watch.', 409);
        }

        return DB::transaction(function () use ($room, $user, $seatNumber) {
            $seat = $room->seats()->where('seat_number', $seatNumber)->lockForUpdate()->first();

            if ($seat === null) {
                throw new RoomException('NOT_FOUND', 'That seat does not exist in this room.', 404);
            }

            if ($seat->is_locked) {
                throw new RoomException('SEAT_LOCKED', 'That seat is locked.', 409);
            }

            // Mazag: a VIP seat is for VIP members only.
            if ($seat->is_vip && $this->inventory->activeVip($user) === null) {
                throw new RoomException('VIP_SEAT', 'This mic slot is for VIPs only.', 403);
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
                'is_camera_on'  => $room->kind === Room::KIND_VIDEO_PARTY,
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

    /** "The host removed you from this room. You can join again in 4 min." — says how long is left. */
    protected function blockedMessage(Room $room, User $user): string
    {
        return RoomKickNotice::blocked($room, $user);
    }
}
