<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §13 — the in-app inbox.
class Notification extends Model
{
    protected $fillable = [
        'user_id', 'admin_user_id', 'type', 'title', 'body', 'data',
        'image_url', 'deep_link', 'channel', 'is_read', 'read_at', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'data'    => 'array',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }
}
