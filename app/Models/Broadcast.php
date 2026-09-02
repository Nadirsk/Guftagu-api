<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §13 — A.10a, GFT-105.
class Broadcast extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';
    public const SCHEDULED = 'scheduled';
    public const SENDING = 'sending';
    public const SENT = 'sent';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';

    public const AUDIENCES = ['all', 'segment', 'user_list'];

    public const CHANNELS = ['push', 'in_app'];

    protected $fillable = [
        'uuid', 'title', 'body', 'image_url', 'deep_link', 'audience', 'audience_filter',
        'channels', 'audience_count', 'scheduled_at', 'status', 'sent_count',
        'delivered_count', 'opened_count', 'sent_at', 'created_by', 'approved_by',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getIncrementing(): bool
    {
        return true;
    }

    public function getKeyType(): string
    {
        return 'int';
    }

    protected function casts(): array
    {
        return [
            'audience_filter' => 'array',
            'channels'        => 'array',
            'audience_count'  => 'integer',
            'scheduled_at'    => 'datetime',
            'sent_count'      => 'integer',
            'delivered_count' => 'integer',
            'opened_count'    => 'integer',
            'sent_at'         => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by');
    }

    /** Only a draft or a scheduled-but-unsent campaign can still be changed. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::SCHEDULED], true);
    }

    public function isSent(): bool
    {
        return $this->status === self::SENT;
    }

    /**
     * Delivered ÷ sent. Null when nothing has gone out — zero would read as
     * "nothing arrived", which is a different and false statement.
     */
    public function deliveryRate(): ?float
    {
        return $this->sent_count > 0 ? round($this->delivered_count / $this->sent_count, 4) : null;
    }

    public function openRate(): ?float
    {
        return $this->delivered_count > 0 ? round($this->opened_count / $this->delivered_count, 4) : null;
    }
}
