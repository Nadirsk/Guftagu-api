<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * docs/00 §7's wealth/charm progression, admin-configured. See the creating migration
 * for why a user's level is never stored here — it is resolved against this ladder at
 * read time from `Wallet::lifetime_coins_spent` / `lifetime_diamonds_earned`, or from
 * an admin override on the wallet (GFT-027).
 */
class WealthCharmLevel extends Model
{
    public const TYPES = ['wealth', 'charm'];

    protected $fillable = [
        'type', 'level', 'name_en', 'name_hi', 'threshold', 'badge_url', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'level'     => 'integer',
            'threshold' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * The highest active level whose threshold the given lifetime total has reached, or
     * null if it hasn't reached even level 1 yet. Read-time resolution — see the class
     * docblock for why nothing writes this onto a user.
     */
    public static function resolveFor(string $type, int $lifetimeValue): ?self
    {
        return static::query()
            ->ofType($type)->active()
            ->where('threshold', '<=', $lifetimeValue)
            ->orderByDesc('threshold')
            ->first();
    }

    /** The next level up, so the panel/app can show "3,400 coins to Wealth 6". */
    public static function nextAfter(string $type, int $lifetimeValue): ?self
    {
        return static::query()
            ->ofType($type)->active()
            ->where('threshold', '>', $lifetimeValue)
            ->orderBy('threshold')
            ->first();
    }
}
