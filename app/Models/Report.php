<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// docs/02 §11 — A.5b, C.3.
class Report extends Model
{
    use HasUuids;

    public const OPEN = 'open';
    public const ASSIGNED = 'assigned';
    public const ACTIONED = 'actioned';
    public const DISMISSED = 'dismissed';
    public const ESCALATED = 'escalated';

    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];
    public const CATEGORIES = ['abuse', 'nudity', 'harassment', 'spam', 'fraud', 'underage', 'other'];

    protected $fillable = [
        'uuid', 'reporter_id', 'target_type', 'target_id', 'category', 'description',
        'evidence_urls', 'audio_clip_url', 'priority', 'status', 'assigned_to',
        'assigned_at', 'claimed_by', 'claimed_at', 'resolved_by', 'resolved_at',
        'resolution_note', 'escalated_to', 'escalated_at',
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
            'evidence_urls' => 'array',
            'assigned_at'   => 'datetime',
            'claimed_at'    => 'datetime',
            'resolved_at'   => 'datetime',
            'escalated_at'  => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_to');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'resolved_by');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ReportAction::class)->latest('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::OPEN, self::ASSIGNED, self::ESCALATED], true);
    }

    /**
     * A.5b — critical above high above medium above low, oldest first inside a priority.
     *
     * MySQL has no natural order for these strings, so FIELD() imposes one. Sorting on the
     * raw column would put "critical" after "high" alphabetically, which is the exact
     * opposite of what a queue needs.
     */
    public function scopeQueueOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw("FIELD(priority, 'critical', 'high', 'medium', 'low')")
            ->orderBy('created_at');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::OPEN, self::ASSIGNED, self::ESCALATED]);
    }

    // ------------------------------------------------------------ claims (C.3a)

    /**
     * How long a claim holds before anyone else may take the report.
     *
     * Twenty minutes is long enough to read the evidence and decide, and short enough that
     * a moderator who wandered off does not park a critical report indefinitely.
     */
    public const CLAIM_MINUTES = 20;

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'claimed_by');
    }

    /**
     * Whether a claim is currently holding.
     *
     * **Derived from `claimed_at`, not from `claimed_by` alone.** A stale claim releases
     * itself the moment its window passes, with nothing having run — the same rule this
     * codebase uses for sanctions, featured rooms and banner windows. Keying off the id
     * alone would need a job to clear it, and a stalled job would leave reports locked.
     */
    public function isClaimed(): bool
    {
        return $this->claimed_by !== null
            && $this->claimed_at !== null
            && $this->claimed_at->diffInMinutes(now()) < self::CLAIM_MINUTES;
    }

    /** Whether this admin may act: unclaimed, claimed by them, or the claim has lapsed. */
    public function isActionableBy(int $adminId): bool
    {
        return ! $this->isClaimed() || $this->claimed_by === $adminId;
    }

    /** Minutes left on the claim, or null when nothing is holding it. */
    public function claimExpiresIn(): ?int
    {
        if (! $this->isClaimed()) {
            return null;
        }

        return max(0, self::CLAIM_MINUTES - (int) $this->claimed_at->diffInMinutes(now()));
    }

    /** The same window in SQL, so a filtered list agrees with `isClaimed()`. */
    public function scopeClaimHolding(Builder $query): Builder
    {
        return $query->whereNotNull('claimed_by')
            ->where('claimed_at', '>', now()->subMinutes(self::CLAIM_MINUTES));
    }
}
