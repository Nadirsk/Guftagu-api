<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// docs/02 §8 — A.9c.
class RankingRule extends Model
{
    public const BOARD_TYPES = ['wealth', 'charm', 'cp', 'room', 'agency'];
    public const PERIODS = ['daily', 'weekly', 'monthly', 'all_time'];
    public const METRICS = ['coins_spent', 'diamonds_earned', 'cp_points'];

    /** Boards whose standings can be computed from the gift ledger today. */
    public const COMPUTABLE_BOARDS = ['wealth', 'charm'];

    /**
     * Whether the board can be ranked yet. CP has no pairs or points server-side, so it is
     * a rewards-only board: the admin sets what each place pays, nothing is ranked or paid.
     */
    public function isComputable(): bool
    {
        return in_array($this->board_type, self::COMPUTABLE_BOARDS, true);
    }

    protected $fillable = [
        'key', 'board_type', 'period', 'metric',
        'min_threshold', 'top_n', 'reset_cron', 'is_active',
    ];

    protected function casts(): array
    {
        return ['min_threshold' => 'integer', 'top_n' => 'integer', 'is_active' => 'boolean'];
    }
}
