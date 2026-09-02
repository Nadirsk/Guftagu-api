<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A panel account. Deliberately NOT the same model as App\Models\User (the mobile-app
 * account) — see docs/02 §2.2. They share no lifecycle, no auth path and no threat model.
 */
class AdminUser extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'role_id', 'avatar_url', 'phone',
        'mfa_enabled', 'mfa_secret', 'session_timeout_minutes', 'status', 'created_by',
    ];

    protected $hidden = ['password', 'mfa_secret', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password'      => 'hashed',
            'mfa_secret'    => 'encrypted',
            'mfa_enabled'   => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    /**
     * Direct grants and explicit denies from admin_user_permission.
     *
     * Chain ->allow() / ->deny() / ->notExpired() (scopes on Permission) to narrow;
     * this is the shape PermissionResolver in docs/01 §5.1 is written against.
     */
    public function directGrants(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'admin_user_permission')
            ->withPivot(['effect', 'granted_by', 'expires_at', 'scope'])
            ->withTimestamps();
    }

    public function mfaChallenges(): HasMany
    {
        return $this->hasMany(AdminMfaChallenge::class);
    }

    // ------------------------------------------------------------------ helpers

    public function isSuperAdmin(): bool
    {
        return $this->role?->key === Role::SUPER_ADMIN;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function roleKey(): ?string
    {
        return $this->role?->key;
    }

    /**
     * Who this admin may grant permissions to (docs/01 §5.3).
     *
     * Super Admin  → anyone but themselves
     * Admin        → Manager and Moderator only
     * everyone else → nobody. A Manager attempting a grant gets DELEGATION_TARGET_DENIED.
     */
    public function canDelegateTo(self $target): bool
    {
        if ($this->is($target)) {
            return false;
        }

        return match ($this->roleKey()) {
            Role::SUPER_ADMIN => true,
            Role::ADMIN       => in_array($target->roleKey(), [Role::MANAGER, Role::MODERATOR], true),
            default           => false,
        };
    }

    /**
     * The effective idle timeout in minutes: the per-account override if set,
     * otherwise the platform default (A.1c).
     */
    public function sessionTimeoutMinutes(): int
    {
        return $this->session_timeout_minutes
            ?? (int) app(\App\Domain\Settings\SettingsRepository::class)->get('security.session_timeout_minutes', 60);
    }
}
