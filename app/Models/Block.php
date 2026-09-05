<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * docs/02 §12 — D.9c.
 *
 * A block is **directional as a record and symmetric as a rule**: only the blocker's row
 * exists, but neither side may DM, call, gift or see the other, and neither appears in the
 * other's follower list. Every enforcement point therefore asks {@see existsBetween()},
 * which checks both directions — asking `blocker_id = me` alone lets the person I blocked
 * carry on messaging me.
 */
class Block extends Model
{
    protected $fillable = ['blocker_id', 'blocked_id', 'reason'];

    /**
     * Whether a block exists in either direction.
     *
     * Cached for a minute because this runs on every DM send, every profile view and every
     * feed page. The cache is flushed on both block and unblock, so an unblock takes effect
     * at once rather than after the TTL.
     */
    public static function existsBetween(int $a, int $b): bool
    {
        return Cache::remember(static::cacheKey($a, $b), 60, fn () => static::query()
            ->where(fn ($q) => $q->where('blocker_id', $a)->where('blocked_id', $b))
            ->orWhere(fn ($q) => $q->where('blocker_id', $b)->where('blocked_id', $a))
            ->exists());
    }

    public static function forget(int $a, int $b): void
    {
        Cache::forget(static::cacheKey($a, $b));
    }

    protected static function cacheKey(int $a, int $b): string
    {
        [$low, $high] = $a < $b ? [$a, $b] : [$b, $a];

        return "block:{$low}:{$high}";
    }

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocker_id');
    }

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }
}
