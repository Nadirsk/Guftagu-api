<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * docs/02 §9 — A.9a/b.
 *
 * The `status` column is the operator's intent; the **phase** is derived. A.9a demands
 * that a scheduled event flips to live at its start time and to ended at its end time
 * "with no manual step", so those two are computed from the clock rather than written by a
 * job. A stalled scheduler then cannot strand an event in the wrong state.
 */
class Event extends Model
{
    use HasUuids, SoftDeletes;

    public const DRAFT = 'draft';
    public const SCHEDULED = 'scheduled';
    public const CANCELLED = 'cancelled';

    public const UPCOMING = 'upcoming';
    public const LIVE = 'live';
    public const ENDED = 'ended';

    public const TYPES = ['event', 'tournament', 'lucky_draw', 'recharge_activity', 'weekly_star', 'custom'];

    /**
     * Template-driven types — the ones built on event_tiers/event_progress/event_claims.
     * `recharge_activity` and `weekly_star` keep their own dedicated mechanisms
     * (recharge_amount; a RankingRule via ranking_rule_key) exactly as before —
     * `custom` is the general case: any progress metric, any tier_type, fixed dates or
     * a recurring period, entirely admin-configured. Eligibility for the builder is
     * membership in this list, not a stored flag — see `isCampaign()`.
     */
    public const CAMPAIGN_TYPES = ['recharge_activity', 'weekly_star', 'custom'];

    public const PERIODS = ['daily', 'weekly', 'monthly'];

    /**
     * What a campaign event's progress (threshold tiers) or standings (rank_range
     * tiers, when not riding a RankingRule) measure — see EventCampaignService.
     * `gift_value`/`gift_count` read `metric_ref_id`/`metric_ref_type` to optionally
     * narrow to one specific gift or gift category.
     */
    public const PROGRESS_METRICS = ['recharge_amount', 'coins_spent', 'diamonds_earned', 'gift_value', 'gift_count'];

    public const METRIC_REF_TYPES = ['gift', 'gift_category'];

    /**
     * The block library a campaign screen is composed from (docs/03 — Event Builder).
     * `layout` is an ordered array of `{id, type, config, style}` instances of these —
     * an admin adds, reorders, configures and styles them; the app only ever needs to
     * know this one set of block types, so a new *screen* never needs a code change,
     * only new blocks in the array. `text`/`image`/`spacer` exist so a layout is never
     * boxed into only the campaign-specific blocks.
     */
    public const BLOCK_TYPES = [
        'banner', 'countdown', 'my_progress_card', 'tier_grid', 'tabs',
        'leaderboard_list', 'reward_bundle_card', 'rules_button',
        'text', 'image', 'spacer',
    ];

    protected $fillable = [
        'uuid', 'type', 'title_en', 'title_hi', 'description', 'banner_url', 'rules', 'layout',
        'entry_type', 'entry_cost', 'eligibility', 'starts_at', 'ends_at', 'period',
        'progress_metric', 'metric_ref_id', 'metric_ref_type', 'ranking_rule_key', 'status',
        'created_by', 'approved_by', 'max_participants', 'is_featured',
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
            'rules'            => 'array',
            'layout'           => 'array',
            'eligibility'      => 'array',
            'starts_at'        => 'datetime',
            'ends_at'          => 'datetime',
            'entry_cost'       => 'integer',
            'max_participants' => 'integer',
            'is_featured'      => 'boolean',
            'metric_ref_id'    => 'integer',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function participants(): HasMany
    {
        return $this->hasMany(EventParticipant::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(EventReward::class)->orderBy('rank_from');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(EventRewardClaim::class);
    }

    /** Campaign types only (recharge_activity, weekly_star). */
    public function tiers(): HasMany
    {
        return $this->hasMany(EventTier::class)->orderBy('sort_order');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(EventProgress::class);
    }

    public function tierClaims(): HasMany
    {
        return $this->hasMany(EventClaim::class);
    }

    public function isCampaign(): bool
    {
        return in_array($this->type, self::CAMPAIGN_TYPES, true);
    }

    public function luckyDraw(): HasOne
    {
        return $this->hasOne(LuckyDraw::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    // ------------------------------------------------------------------ phase

    /**
     * What the app should show. Draft and cancelled are operator states and win outright;
     * everything else follows the clock.
     */
    public function phase(): string
    {
        if ($this->status === self::DRAFT || $this->status === self::CANCELLED) {
            return $this->status;
        }

        if ($this->starts_at->isFuture()) {
            return self::UPCOMING;
        }

        return $this->ends_at->isFuture() ? self::LIVE : self::ENDED;
    }

    public function isLive(): bool
    {
        return $this->phase() === self::LIVE;
    }

    public function hasEnded(): bool
    {
        return $this->phase() === self::ENDED;
    }

    /** Published to the app: anything scheduled, regardless of phase. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::SCHEDULED);
    }

    /**
     * Live right now. The window is evaluated in SQL so the app's list is correct at the
     * instant it is read, not at the instant a job last ran.
     */
    public function scopeLiveNow(Builder $query): Builder
    {
        return $query->where('status', self::SCHEDULED)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', self::SCHEDULED)->where('starts_at', '>', now());
    }

    public function scopeEnded(Builder $query): Builder
    {
        return $query->where('status', self::SCHEDULED)->where('ends_at', '<=', now());
    }
}
