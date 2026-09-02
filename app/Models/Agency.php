<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// docs/02 §10 — A.8a.
class Agency extends Model
{
    use HasUuids, SoftDeletes;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const SUSPENDED = 'suspended';
    public const REJECTED = 'rejected';

    public const STATUSES = [self::PENDING, self::APPROVED, self::SUSPENDED, self::REJECTED];

    protected $fillable = [
        'uuid', 'code', 'name', 'owner_user_id', 'logo_url', 'description',
        'contact_phone', 'contact_phone_hash', 'contact_email', 'contact_email_hash',
        'documents', 'commission_bp', 'status', 'approved_by', 'approved_at',
        'rejection_reason', 'managed_by',
    ];

    protected $hidden = ['contact_phone', 'contact_email', 'contact_phone_hash', 'contact_email_hash'];

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
            'contact_phone' => 'encrypted',
            'contact_email' => 'encrypted',
            'documents'     => 'array',
            'commission_bp' => 'integer',
            'approved_at'   => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Same rule as User: the hash is maintained here so it cannot drift from the
        // ciphertext, and so a caller who forgets does not create an unfindable agency.
        static::saving(function (self $agency) {
            if ($agency->isDirty('contact_phone')) {
                $agency->contact_phone_hash = $agency->contact_phone === null
                    ? null : User::hash($agency->contact_phone);
            }

            if ($agency->isDirty('contact_email')) {
                $agency->contact_email_hash = $agency->contact_email === null
                    ? null : User::hash($agency->contact_email);
            }
        });
    }

    // ---------------------------------------------------------------- relations

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'managed_by');
    }

    public function hosts(): HasMany
    {
        return $this->hasMany(Host::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(AgencyMember::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class)->latest('period_start');
    }

    // ------------------------------------------------------------------- scopes

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")
                // Contact details are ciphertext, so these are exact hash lookups, not
                // LIKE — a partial number cannot match one.
                ->orWhere('contact_phone_hash', User::hash($term))
                ->orWhere('contact_email_hash', User::hash($term));
        });
    }

    // ------------------------------------------------------------------ helpers

    /** Only an approved agency may take hosts or be settled. */
    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function maskedPhone(): ?string
    {
        if ($this->contact_phone === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $this->contact_phone) ?? '';

        return strlen($digits) < 4
            ? str_repeat('•', 6)
            : substr($digits, 0, 2).str_repeat('•', 6).substr($digits, -2);
    }

    public function maskedEmail(): ?string
    {
        if ($this->contact_email === null) {
            return null;
        }

        [$local, $domain] = array_pad(explode('@', $this->contact_email, 2), 2, '');

        return mb_substr($local, 0, 2).str_repeat('•', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }

    /** AGY-0001. Sequential rather than random so support can read one down a phone. */
    public static function nextCode(): string
    {
        $last = static::withTrashed()->max('id') ?? 0;

        return 'AGY-'.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }
}
