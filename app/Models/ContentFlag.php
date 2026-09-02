<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §11 — what the filter caught but let through.
class ContentFlag extends Model
{
    protected $fillable = [
        'content_type', 'content_id', 'user_id', 'flagged_by', 'rule_matched',
        'confidence', 'excerpt', 'status', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['confidence' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }
}
