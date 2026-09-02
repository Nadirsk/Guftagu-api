<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §11 — C.4c. Append-only: no updated_at, never edited.
class ModerationLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_user_id', 'action', 'target_type', 'target_id',
        'room_id', 'before', 'after', 'reason', 'ip',
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
