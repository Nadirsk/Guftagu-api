<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    public const SUPER_ADMIN = 'super_admin';
    public const ADMIN       = 'admin';
    public const MANAGER     = 'manager';
    public const MODERATOR   = 'moderator';

    protected $fillable = ['key', 'name', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function adminUsers(): HasMany
    {
        return $this->hasMany(AdminUser::class);
    }

    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }
}
