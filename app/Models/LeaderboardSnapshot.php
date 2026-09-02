<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// docs/02 §8 — the snapshot is the record; Redis is only the working surface.
class LeaderboardSnapshot extends Model
{
    protected $fillable = [
        'rule_key', 'period_start', 'period_end', 'rank', 'entity_type', 'entity_id', 'score',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end'   => 'date',
            'rank'         => 'integer',
            'entity_id'    => 'integer',
            'score'        => 'integer',
        ];
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(RankingRewardPayout::class, 'snapshot_id');
    }
}
