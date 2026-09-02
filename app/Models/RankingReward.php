<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// docs/02 §8 — A.9d.
class RankingReward extends Model
{
    protected $fillable = ['rule_key', 'rank_from', 'rank_to', 'reward_type', 'reward_value', 'is_active'];

    protected function casts(): array
    {
        return [
            'rank_from'    => 'integer',
            'rank_to'      => 'integer',
            'reward_value' => 'integer',
            'is_active'    => 'boolean',
        ];
    }

    public function covers(int $rank): bool
    {
        return $rank >= $this->rank_from && $rank <= $this->rank_to;
    }
}
