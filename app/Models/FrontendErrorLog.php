<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// IT Admin epic — append-only, like AuditLog. No updated_at: a report is a historical fact.
class FrontendErrorLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_user_id', 'level', 'message', 'stack', 'source_url', 'user_agent', 'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
