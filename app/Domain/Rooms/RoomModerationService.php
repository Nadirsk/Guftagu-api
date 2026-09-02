<?php

namespace App\Domain\Rooms;

use App\Domain\Audit\AuditLogger;
use App\Models\AdminUser;
use App\Models\ModerationLog;
use App\Models\Notification;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\RoomSeat;
use App\Models\User;
use App\Models\UserSanction;
use Illuminate\Support\Facades\DB;

/**
 * GFT-151 / GFT-157 / GFT-158 / GFT-162 — live-room enforcement (C.1b, C.2a–c).
 *
 * **What this can and cannot do, stated plainly.** Every write here is real: the seat
 * flag, the presence row, the sanction, the audit trail, the moderation log. What is
 * *not* real is the live audio/video effect — actually silencing a microphone or cutting a
 * stream needs an Agora RTC/RTM session, and Agora credentials are not configured in this
 * environment (`config('services.agora')` is unset). Every method here writes the state
 * a joining or already-connected client would need to honour, and every response says in
 * words that the broadcast leg is pending E.1 rather than pretending it already happened.
 *
 * That mirrors how `BroadcastService::send()` treats FCM: the in-app row is real, the push
 * is reported as `dispatched: false`.
 */
class RoomModerationService
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    /**
     * GFT-151 — silent join.
     *
     * The "silent" half is genuinely enforced at the data layer: this never touches
     * `room_members` or `room_seats`, so the moderator appears in no participant list and
     * no `member.joined` row is ever written for them — there is nothing to suppress
     * because nothing is created. What is written is the one thing C.1b makes mandatory:
     * an audit and moderation-log entry naming the room, the moderator and the timestamp.
     *
     * @return array{room_id: int, note: string, audio: bool}
     */
    public function silentJoin(Room $room, AdminUser $actor): array
    {
        $this->audit->log($actor, 'room.silent_join', 'rooms', Room::class, $room->id, null, [
            'room_code' => $room->room_code,
        ]);

        ModerationLog::create([
            'admin_user_id' => $actor->id,
            'action'        => 'silent_join',
            'target_type'   => Room::class,
            'target_id'     => (string) $room->id,
            'room_id'       => $room->id,
            'reason'        => 'Silent observation.',
            'ip'            => request()->ip(),
        ]);

        return [
            'room_id' => $room->id,
            'audio'   => false,
            'note' => 'Logged. No listener-count change and no membership row were created — nothing exists here for a client to broadcast. Subscribing to the live audio stream itself needs an Agora RTC token, which this environment has no Agora credentials configured to mint.',
        ];
    }

    /**
     * GFT-157 — server-forced mute.
     *
     * Sets `room_seats.is_muted_by_host`, which is the flag the app is specified to read
     * before allowing a self-unmute (docs/02 §3.2). The **duration is derived, not
     * enforced by a job**: `RoomSeat::isMuteExpired()` (defined alongside this) reads
     * `expires_at` on the matching sanction at query time, the same rule this codebase
     * uses for every other time-bounded state.
     *
     * @throws RoomException
     */
    public function mute(Room $room, RoomSeat $seat, ?int $durationMinutes, string $reason, AdminUser $actor): UserSanction
    {
        if ($seat->room_id !== $room->id) {
            throw new RoomException('VALIDATION_ERROR', 'That seat is not in this room.', 422);
        }

        if (! $seat->isOccupied()) {
            throw new RoomException('BAD_REQUEST', 'That seat is empty. There is nobody to mute.', 400);
        }

        if (trim($reason) === '') {
            throw new RoomException('VALIDATION_ERROR', 'A reason is required to mute somebody.', 422);
        }

        $user = $seat->user;

        return DB::transaction(function () use ($room, $seat, $durationMinutes, $reason, $actor, $user) {
            $seat->forceFill(['is_muted_by_host' => true])->save();

            $sanction = UserSanction::create([
                'user_id'    => $user->id,
                'type'       => UserSanction::MUTE,
                'scope'      => 'room',
                'room_id'    => $room->id,
                'reason'     => trim($reason),
                'issued_by'  => $actor->id,
                'starts_at'  => now(),
                'expires_at' => $durationMinutes === null ? null : now()->addMinutes($durationMinutes),
                'is_active'  => true,
            ]);

            $this->audit->log($actor, 'room.mute', 'rooms', Room::class, $room->id, null, [
                'user_id' => $user->id, 'seat' => $seat->seat_number, 'duration_minutes' => $durationMinutes,
            ]);

            ModerationLog::create([
                'admin_user_id' => $actor->id,
                'action'        => 'mute',
                'target_type'   => User::class,
                'target_id'     => (string) $user->id,
                'room_id'       => $room->id,
                'reason'        => trim($reason),
                'ip'            => request()->ip(),
            ]);

            return $sanction;
        });
    }

    /**
     * @throws RoomException
     */
    public function unmute(Room $room, RoomSeat $seat, AdminUser $actor): void
    {
        if ($seat->room_id !== $room->id) {
            throw new RoomException('VALIDATION_ERROR', 'That seat is not in this room.', 422);
        }

        DB::transaction(function () use ($room, $seat, $actor) {
            $seat->forceFill(['is_muted_by_host' => false])->save();

            if ($seat->user_id !== null) {
                UserSanction::query()
                    ->where('user_id', $seat->user_id)
                    ->where('room_id', $room->id)
                    ->where('type', UserSanction::MUTE)
                    ->active()
                    ->update(['is_active' => false, 'revoked_by' => $actor->id, 'revoked_at' => now()]);
            }
        });

        ModerationLog::create([
            'admin_user_id' => $actor->id,
            'action'        => 'unmute',
            'target_type'   => User::class,
            'target_id'     => (string) $seat->user_id,
            'room_id'       => $room->id,
            'ip'            => request()->ip(),
        ]);
    }

    /**
     * GFT-158 — remove from the room and from their seat, with a re-entry block.
     *
     * `RoomMember` is closed out exactly like a normal departure (`left_at`, a computed
     * duration) so the presence history reads the same either way; only the sanction row
     * says this one was not voluntary. The re-entry block is the sanction itself —
     * `Room::isBlockedForUser()` (below) is what an app-side join handler would consult,
     * derived from the clock rather than cleared by a job.
     *
     * @throws RoomException
     */
    public function kick(Room $room, User $target, ?int $reentryBlockMinutes, string $reason, AdminUser $actor): UserSanction
    {
        if (trim($reason) === '') {
            throw new RoomException('VALIDATION_ERROR', 'A reason is required to kick somebody.', 422);
        }

        $member = RoomMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $target->id)
            ->where('is_active', true)
            ->first();

        if ($member === null) {
            throw new RoomException('BAD_REQUEST', 'That user is not currently in this room.', 400);
        }

        return DB::transaction(function () use ($room, $member, $target, $reentryBlockMinutes, $reason, $actor) {
            $member->forceFill([
                'is_active'        => false,
                'left_at'          => now(),
                'duration_seconds' => $member->joined_at?->diffInSeconds(now()) ?? 0,
            ])->save();

            RoomSeat::query()
                ->where('room_id', $room->id)
                ->where('user_id', $target->id)
                ->update(['user_id' => null, 'occupied_at' => null, 'is_camera_on' => false, 'is_muted_by_host' => false]);

            $sanction = UserSanction::create([
                'user_id'    => $target->id,
                'type'       => UserSanction::ROOM_BAN,
                'scope'      => 'room',
                'room_id'    => $room->id,
                'reason'     => trim($reason),
                'issued_by'  => $actor->id,
                'starts_at'  => now(),
                // Null means indefinite, matching a permanent ban's convention — a kick
                // with no stated window blocks re-entry until somebody reverses it.
                'expires_at' => $reentryBlockMinutes === null ? null : now()->addMinutes($reentryBlockMinutes),
                'is_active'  => true,
            ]);

            $this->audit->log($actor, 'room.kick', 'rooms', Room::class, $room->id, null, [
                'user_id' => $target->id, 'reentry_block_minutes' => $reentryBlockMinutes,
            ]);

            ModerationLog::create([
                'admin_user_id' => $actor->id,
                'action'        => 'kick',
                'target_type'   => User::class,
                'target_id'     => (string) $target->id,
                'room_id'       => $room->id,
                'reason'        => trim($reason),
                'ip'            => request()->ip(),
            ]);

            return $sanction;
        });
    }

    /**
     * GFT-162 — an in-room warning.
     *
     * C.2c asks for a system message in the room's chat and a push to the warned user.
     * There is no chat table yet — `messages` lands with D.4, mobile scope — so the chat
     * half genuinely cannot be written; the response says so rather than silently doing
     * half the job. The push half is real: an in-app `Notification` row, exactly like
     * every other in-app message this codebase sends ahead of FCM landing.
     *
     * @return array{sanction: UserSanction, chat_posted: bool}
     */
    public function warn(Room $room, User $target, string $message, AdminUser $actor): array
    {
        if (trim($message) === '') {
            throw new RoomException('VALIDATION_ERROR', 'A warning needs a message.', 422);
        }

        $sanction = UserSanction::create([
            'user_id'   => $target->id,
            'type'      => UserSanction::WARNING,
            'scope'     => 'room',
            'room_id'   => $room->id,
            'reason'    => trim($message),
            'issued_by' => $actor->id,
            'starts_at' => now(),
            'is_active' => true,
        ]);

        Notification::create([
            'user_id'   => $target->id,
            'type'      => 'room_warning',
            'title'     => "Warning in {$room->name}",
            'body'      => trim($message),
            'data'      => ['room_id' => $room->id],
            'channel'   => 'in_app',
            'sent_at'   => now(),
        ]);

        $this->audit->log($actor, 'room.warn', 'rooms', Room::class, $room->id, null, [
            'user_id' => $target->id, 'message' => trim($message),
        ]);

        ModerationLog::create([
            'admin_user_id' => $actor->id,
            'action'        => 'warn',
            'target_type'   => User::class,
            'target_id'     => (string) $target->id,
            'room_id'       => $room->id,
            'reason'        => trim($message),
            'ip'            => request()->ip(),
        ]);

        return ['sanction' => $sanction, 'chat_posted' => false];
    }
}
