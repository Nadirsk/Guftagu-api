<?php

namespace App\Domain\Rooms;

use App\Models\Room;
use App\Models\User;
use App\Models\UserSanction;
use Illuminate\Support\Carbon;

/**
 * The words a kicked person reads: who put them out (the room owner, a room admin or Guftagu's
 * moderators) and for how long. Used by the live `member.kicked` broadcast and by the 403 that
 * `/join` and `/state` answer while the re-entry block lasts.
 */
class RoomKickNotice
{
    /** Stored as the room ban's `reason` for a kick from inside the room. */
    public const REASON_OWNER = 'Kicked out by the room owner';
    public const REASON_ADMIN = 'Kicked out by a room admin';
    /** A room ban with no end: the room's block list. */
    public const REASON_BLOCK_OWNER = 'Blocked by the room owner';
    public const REASON_BLOCK_ADMIN = 'Blocked by a room admin';

    /** `owner`, `admin` or `staff` — staff kicks carry `issued_by` (an admin_users id). */
    public static function by(UserSanction $sanction): string
    {
        if ($sanction->issued_by !== null) {
            return 'staff';
        }

        return in_array($sanction->reason, [self::REASON_ADMIN, self::REASON_BLOCK_ADMIN], true) ? 'admin' : 'owner';
    }

    /** Whether this ban is a block-list entry rather than a timed kick. */
    public static function isBlock(UserSanction $sanction): bool
    {
        return in_array($sanction->reason, [self::REASON_BLOCK_OWNER, self::REASON_BLOCK_ADMIN], true);
    }

    /** "The room owner blocked you from this room." */
    public static function blockedBy(string $by): string
    {
        return ($by === 'admin' ? 'A room admin' : 'The room owner').' blocked you from this room.';
    }

    /** "The room owner kicked you out of this room for 5 minutes." */
    public static function kicked(string $by, ?int $minutes): string
    {
        $span = $minutes === null ? '' : ' for '.self::duration($minutes);

        return match ($by) {
            'staff' => 'Guftagu moderators removed you from this room'.$span.'.',
            'admin' => 'A room admin kicked you out of this room'.$span.'.',
            default => 'The room owner kicked you out of this room'.$span.'.',
        };
    }

    /** The newest active room ban on this person, or null once it has lapsed. */
    public static function activeBan(Room $room, User $user): ?UserSanction
    {
        return UserSanction::query()
            ->where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->where('type', UserSanction::ROOM_BAN)
            ->active()
            ->latest('id')
            ->first();
    }

    /** "The room owner kicked you out of this room. You can join again in 4 min." */
    public static function blocked(Room $room, User $user): string
    {
        $ban = self::activeBan($room, $user);

        if ($ban === null) {
            return 'You have been removed from this room.';
        }

        if (self::isBlock($ban)) {
            return self::blockedBy(self::by($ban));
        }

        $who = self::kicked(self::by($ban), null);

        if ($ban->expires_at === null) {
            return $who;
        }

        $minutes = max(1, (int) ceil(now()->diffInSeconds(Carbon::parse($ban->expires_at), false) / 60));

        return $who.' You can join again in '.self::duration($minutes).'.';
    }

    /** 5 → "5 minutes", 60 → "1 hour", 1440 → "24 hours", 10080 → "7 days", 1500 → "1 day 1 hour". */
    public static function duration(int $minutes): string
    {
        $minutes = max(1, $minutes);
        $plural = fn (int $n, string $unit) => $n.' '.$unit.($n === 1 ? '' : 's');

        if ($minutes < 60) {
            return $plural($minutes, 'minute');
        }

        // A whole day count reads as hours up to a day ("24 hours"), as days beyond it.
        if ($minutes <= 1440 && $minutes % 60 === 0) {
            return $plural(intdiv($minutes, 60), 'hour');
        }

        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins = $minutes % 60;

        return implode(' ', array_filter([
            $days ? $plural($days, 'day') : null,
            $hours ? $plural($hours, 'hour') : null,
            ($mins && $days === 0) ? $plural($mins, 'minute') : null,
        ]));
    }
}
