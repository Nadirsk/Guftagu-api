<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// docs/02 §13 — epic B.4.
class SupportTicket extends Model
{
    use HasUuids;

    public const OPEN = 'open';
    public const PENDING = 'pending';
    public const RESOLVED = 'resolved';
    public const CLOSED = 'closed';

    public const STATUSES = [self::OPEN, self::PENDING, self::RESOLVED, self::CLOSED];

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public const CATEGORIES = [
        'payment', 'account', 'room', 'harassment', 'kyc', 'withdrawal', 'bug', 'other',
    ];

    protected $fillable = [
        'uuid', 'ref', 'user_id', 'category', 'subject', 'description', 'attachments',
        'priority', 'status', 'assigned_to', 'assigned_at', 'first_response_at',
        'sla_first_response_minutes', 'sla_resolution_minutes', 'escalated_to',
        'escalated_at', 'escalation_note', 'resolved_at', 'resolved_by', 'resolution',
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
            'attachments'                => 'array',
            'assigned_at'                => 'datetime',
            'first_response_at'          => 'datetime',
            'escalated_at'               => 'datetime',
            'resolved_at'                => 'datetime',
            'sla_first_response_minutes' => 'integer',
            'sla_resolution_minutes'     => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_to');
    }

    public function escalatedTo(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'escalated_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'resolved_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id')->orderBy('id');
    }

    // ------------------------------------------------------------------ state

    public function isOpen(): bool
    {
        return in_array($this->status, [self::OPEN, self::PENDING], true);
    }

    /**
     * Minutes until the first reply is due, or negative when it is late.
     *
     * Null once somebody has replied — the timer stopped, and a countdown that keeps
     * running after it stopped is just a wrong number on a screen.
     */
    public function firstResponseDueIn(): ?int
    {
        if ($this->first_response_at !== null || $this->created_at === null) {
            return null;
        }

        return $this->sla_first_response_minutes - (int) $this->created_at->diffInMinutes(now());
    }

    /** How long the first reply actually took, once it has happened. */
    public function firstResponseMinutes(): ?int
    {
        if ($this->first_response_at === null || $this->created_at === null) {
            return null;
        }

        return (int) $this->created_at->diffInMinutes($this->first_response_at);
    }

    /**
     * Whether the promise has been missed — B.4c's trigger.
     *
     * **Derived, not a stored flag.** A ticket crosses into breach by the clock moving,
     * not by anybody doing anything, so a column would be stale between job runs and a
     * stalled scheduler would hide every breach.
     */
    public function breachedFirstResponse(): bool
    {
        if ($this->created_at === null) {
            return false;
        }

        $answeredAt = $this->first_response_at ?? now();

        return $this->created_at->diffInMinutes($answeredAt) > $this->sla_first_response_minutes;
    }

    public function breachedResolution(): bool
    {
        if ($this->created_at === null || ! $this->isOpen()) {
            return false;
        }

        return $this->created_at->diffInMinutes(now()) > $this->sla_resolution_minutes;
    }

    /** One word for the whole SLA position, which is what a queue actually sorts on. */
    public function slaState(): string
    {
        if (! $this->isOpen()) {
            return 'closed';
        }

        if ($this->breachedResolution()) {
            return 'resolution_breached';
        }

        if ($this->first_response_at === null && $this->breachedFirstResponse()) {
            return 'response_breached';
        }

        $due = $this->firstResponseDueIn();

        // "At risk" is worth a distinct state: a ticket 20 minutes from breach is the one
        // somebody should pick up now, and it looks identical to a fresh one otherwise.
        if ($due !== null && $due <= 30) {
            return 'at_risk';
        }

        return 'on_track';
    }

    // ----------------------------------------------------------------- scopes

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::OPEN, self::PENDING]);
    }

    /** Urgent first, then oldest — the same reasoning as the reports queue. */
    public function scopeQueueOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low')")
            ->orderBy('created_at');
    }

    /** Past the first-response promise and still unanswered, evaluated in SQL. */
    public function scopeBreaching(Builder $query): Builder
    {
        return $query->open()
            ->whereNull('first_response_at')
            ->whereRaw('TIMESTAMPDIFF(MINUTE, created_at, NOW()) > sla_first_response_minutes');
    }

    public static function nextRef(): string
    {
        return 'TKT-'.str_pad((string) ((static::max('id') ?? 0) + 1), 6, '0', STR_PAD_LEFT);
    }
}
