<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * The mobile-app account — docs/02 §2.1. Deliberately NOT AdminUser: panel staff and app
 * users share no lifecycle, no auth path and no threat model.
 *
 * `phone` and `email` are encrypted at rest, which makes them unsearchable. Every lookup
 * therefore goes through the `_hash` columns, and the hashes are maintained here rather
 * than by callers — a caller that forgot would create a user nobody can find.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_BANNED = 'banned';
    public const STATUS_DELETED = 'deleted';

    protected $fillable = [
        'uuid', 'guftagu_id', 'phone', 'phone_hash', 'country_code', 'email', 'email_hash',
        'password', 'status', 'agora_uid', 'last_active_at', 'registered_ip',
        'consent_version', 'consent_at',
    ];

    protected $hidden = ['password', 'remember_token', 'phone', 'email', 'phone_hash', 'email_hash'];

    /** HasUuids would otherwise try to make `id` a uuid; only the `uuid` column is one. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getIncrementing(): bool
    {
        return true;
    }

    public function getKeyType(): string
    {
        return 'int';
    }

    protected function casts(): array
    {
        return [
            'phone'          => 'encrypted',
            'email'          => 'encrypted',
            'password'       => 'hashed',
            'last_active_at' => 'datetime',
            'consent_at'     => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Keeping the hashes in the model means they cannot drift from the ciphertext.
        static::saving(function (self $user) {
            if ($user->isDirty('phone')) {
                $user->phone_hash = $user->phone === null ? null : static::hash($user->phone);
            }

            if ($user->isDirty('email')) {
                $user->email_hash = $user->email === null ? null : static::hash($user->email);
            }
        });
    }

    /** Deterministic, so the same number always resolves to the same row (A.3a). */
    public static function hash(string $value): string
    {
        return hash('sha256', mb_strtolower(trim($value)));
    }

    // ---------------------------------------------------------------- relations

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function kyc(): HasOne
    {
        return $this->hasOne(UserKyc::class)->latestOfMany();
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * The host record, when this user is one.
     *
     * Named `hostProfile` rather than `host` because "host" reads as the person, and this
     * is the contract attached to them. One row per user — a host who leaves and returns
     * keeps the same record so their earnings history stays in one piece.
     */
    public function hostProfile(): HasOne
    {
        return $this->hasOne(Host::class);
    }

    public function sanctions(): HasMany
    {
        return $this->hasMany(UserSanction::class)->latest('id');
    }

    public function activeSanctions(): HasMany
    {
        return $this->hasMany(UserSanction::class)->active();
    }

    public function coinTransactions(): HasMany
    {
        return $this->hasMany(CoinTransaction::class)->latest('id');
    }

    public function diamondTransactions(): HasMany
    {
        return $this->hasMany(DiamondTransaction::class)->latest('id');
    }

    // ------------------------------------------------------------------ scopes

    /**
     * Search by phone, guftagu_id or display name (A.3a).
     *
     * A phone match is an exact hash lookup, not a LIKE — the column is ciphertext, so
     * there is nothing to pattern-match against. That is why searching a partial number
     * finds nothing while a full one is instant.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('guftagu_id', 'like', "%{$term}%")
                ->orWhereHas('profile', fn (Builder $p) => $p->where('display_name', 'like', "%{$term}%"));

            // Try the term as a phone, with and without the default country code, so
            // "9876543210" and "+919876543210" both find the same person.
            foreach (array_unique([$term, '+91'.ltrim($term, '+'), ltrim($term, '+')]) as $candidate) {
                $q->orWhere('phone_hash', static::hash($candidate));
            }

            if (filter_var($term, FILTER_VALIDATE_EMAIL)) {
                $q->orWhere('email_hash', static::hash($term));
            }
        });
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $status === null || $status === '' ? $query : $query->where('status', $status);
    }

    // ----------------------------------------------------------------- helpers

    public function isBanned(): bool
    {
        return $this->effectiveStatus() === self::STATUS_BANNED;
    }

    /**
     * Whether this account may currently be used.
     *
     * **Derived, not read straight off the column.** A 24-hour suspension writes
     * `status = suspended` and a sanction with an `expires_at`; when that moment passes the
     * sanction lapses on its own (A.5d, "auto-expires without manual action") but nothing
     * rewrites the column. Trusting `status` alone therefore locked people out forever.
     *
     * `moderation:expire-sanctions` reconciles the column and logs the expiry, but the
     * derivation is what makes the release correct even when that job has not run.
     */
    public function isActive(): bool
    {
        return $this->effectiveStatus() === self::STATUS_ACTIVE;
    }

    /**
     * The status that is actually true right now.
     *
     * `deleted` is terminal and never lifted by a sanction lapsing. Otherwise a
     * suspended/banned account whose sanctions have all expired is active again.
     */
    public function effectiveStatus(): string
    {
        if ($this->status === self::STATUS_ACTIVE || $this->status === self::STATUS_DELETED) {
            return $this->status;
        }

        return $this->hasActiveSanction() ? $this->status : self::STATUS_ACTIVE;
    }

    /**
     * Prefers an eager-loaded `activeSanctions` when the caller supplied one — a user list
     * calls this once per row, and an EXISTS query per row is how a list page gets slow.
     */
    public function hasActiveSanction(): bool
    {
        $blocking = [UserSanction::TEMP_BAN, UserSanction::PERMANENT_BAN];

        if ($this->relationLoaded('activeSanctions')) {
            return $this->activeSanctions
                ->contains(fn (UserSanction $s) => in_array($s->type, $blocking, true));
        }

        return $this->sanctions()
            ->active()
            ->whereIn('type', $blocking)
            ->exists();
    }

    /**
     * `+91 98••••••21` — docs/01 §6. The full value needs `users.view_pii` and writes an
     * audit entry, which is why masking lives here and unmasking does not.
     */
    public function maskedPhone(): ?string
    {
        if ($this->phone === null) {
            return null;
        }

        $country = $this->country_code ?: '+91';

        // Strip the country code before masking, so the dialling prefix stays readable and
        // only the subscriber number is hidden — `+91 98••••••21`, exactly as docs/01 §6
        // specifies. Masking the digits blind produces `+9198 ••••••21`, which is wrong.
        $local = $this->phone;

        foreach ([$country, ltrim($country, '+'), '+'] as $prefix) {
            if ($prefix !== '' && str_starts_with($local, $prefix)) {
                $local = substr($local, strlen($prefix));
            }
        }

        $local = preg_replace('/\D/', '', $local) ?? '';

        if (strlen($local) < 4) {
            return $country.' '.str_repeat('•', 6);
        }

        return $country.' '.substr($local, 0, 2).str_repeat('•', 6).substr($local, -2);
    }

    public function maskedEmail(): ?string
    {
        if ($this->email === null) {
            return null;
        }

        [$local, $domain] = array_pad(explode('@', $this->email, 2), 2, '');

        return mb_substr($local, 0, 2).str_repeat('•', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }
}
