<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §13 — append-only (A.10d). No updated_at: a row here is a historical fact.
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_user_id', 'action', 'module', 'entity_type', 'entity_id',
        'before', 'after', 'ip', 'user_agent', 'request_id',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
