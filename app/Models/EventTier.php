<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One rung of a campaign event's ladder — either a recharge threshold ("300K") or a
 * leaderboard rank band ("Top 1"), depending on the owning event's type. See the
 * `create_inventory_tables`/`generalize_events_tables` migrations for why this, rather
 * than `EventReward`/`RankingReward`, is what campaign events use: a tier can carry
 * several reward lines at once (a bundle), those two can only carry one each.
 */
class EventTier extends Model
{
    public const THRESHOLD = 'threshold';
    public const RANK_RANGE = 'rank_range';

    public const TYPES = [self::THRESHOLD, self::RANK_RANGE];

    protected $fillable = [
        'event_id', 'tier_type', 'period', 'threshold_value',
        'rank_from', 'rank_to', 'label', 'image_url', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'threshold_value' => 'integer',
            'rank_from'       => 'integer',
            'rank_to'         => 'integer',
            'sort_order'      => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(EventTierReward::class)->orderBy('sort_order');
    }

    public function coversRank(int $rank): bool
    {
        return $this->tier_type === self::RANK_RANGE && $rank >= $this->rank_from && $rank <= $this->rank_to;
    }

    public function isClearedBy(int $score): bool
    {
        return $this->tier_type === self::THRESHOLD && $score >= $this->threshold_value;
    }
}
