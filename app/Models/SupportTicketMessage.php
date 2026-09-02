<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// docs/02 §13 — one turn in a support conversation.
class SupportTicketMessage extends Model
{
    public const FROM_USER = 'user';
    public const FROM_ADMIN = 'admin';
    public const FROM_SYSTEM = 'system';

    protected $fillable = [
        'ticket_id', 'sender_type', 'sender_id', 'body', 'attachments', 'is_internal',
    ];

    protected function casts(): array
    {
        return ['attachments' => 'array', 'is_internal' => 'boolean'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    /**
     * Whether this message is one the person who raised the ticket can see.
     *
     * A system message is visible — "escalated to an admin" is information they should
     * have. An internal note never is.
     */
    public function isVisibleToUser(): bool
    {
        return ! $this->is_internal;
    }

    /** Only what a staff reply counts as, for the first-response timer. */
    public function scopeStaffReplies(Builder $query): Builder
    {
        return $query->where('sender_type', self::FROM_ADMIN)->where('is_internal', false);
    }
}
