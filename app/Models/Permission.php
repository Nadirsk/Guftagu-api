<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = ['key', 'module', 'action', 'name', 'description', 'risk_level'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }

    public function isHighRisk(): bool
    {
        return $this->risk_level === 'high';
    }

    // ------------------------------------------------------------------- scopes
    // These are written for use on AdminUser::directGrants(), whose query joins
    // admin_user_permission — hence the qualified column names.

    public function scopeAllow(Builder $query): Builder
    {
        return $query->where('admin_user_permission.effect', 'allow');
    }

    public function scopeDeny(Builder $query): Builder
    {
        return $query->where('admin_user_permission.effect', 'deny');
    }

    /**
     * GFT-121 — an expired grant is not in the effective set, whether or not the
     * hourly expiry job has run yet.
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('admin_user_permission.expires_at')
                ->orWhere('admin_user_permission.expires_at', '>', now());
        });
    }

    /**
     * Every permission key in the catalogue. Used by the Super Admin short-circuit
     * in PermissionResolver, so it is cached — the table is effectively static.
     */
    public static function allKeys(): \Illuminate\Support\Collection
    {
        return cache()->remember('cache:permissions:all_keys', 600, fn () => static::query()->pluck('key'));
    }

    public static function forgetKeyCache(): void
    {
        cache()->forget('cache:permissions:all_keys');
    }
}
