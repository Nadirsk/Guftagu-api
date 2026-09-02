<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §11 — A.3c. Every sanction carries a reason; there are no silent bans.
class UserSanction extends Model
{
    public const WARNING = 'warning';
    public const MUTE = 'mute';
    public const ROOM_BAN = 'room_ban';
    public const TEMP_BAN = 'temp_ban';
    public const PERMANENT_BAN = 'permanent_ban';

    protected $fillable = [
        'user_id', 'type', 'scope', 'room_id', 'reason', 'report_id', 'issued_by',
        'starts_at', 'expires_at', 'revoked_by', 'revoked_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_at'  => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'is_active'  => 'boolean',
        ];
    }

    /** Active means not revoked and not lapsed — an expired ban stops biting on its own. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNull('revoked_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'issued_by');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** Whether this window still applies. Derived — a lapsed mute or kick stops on its own. */
    public function isInForce(): bool
    {
        return $this->is_active
            && $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
