<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §11 — the record of what a moderator did, and whether it was later undone.
class ReportAction extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'warn', 'mute', 'kick', 'ban_temp', 'ban_permanent',
        'content_remove', 'room_close', 'dismiss', 'escalate',
    ];

    protected $fillable = [
        'report_id', 'admin_user_id', 'action', 'duration_minutes', 'note',
        'reversed_by', 'reversed_at', 'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'reversed_at'      => 'datetime',
            'created_at'       => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reversed_by');
    }

    public function wasReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
