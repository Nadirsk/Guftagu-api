<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// GFT-114 — append-only: never updated, never deleted (docs/02 §2.4).
class PermissionGrantLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'permission_grants_log';

    protected $fillable = [
        'actor_id', 'target_id', 'permission_id', 'action',
        'effect_before', 'effect_after', 'scope', 'reason', 'ip',
    ];

    protected function casts(): array
    {
        return ['scope' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'actor_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'target_id');
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
