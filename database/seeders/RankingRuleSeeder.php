<?php

namespace Database\Seeders;

use App\Models\RankingRule;
use Illuminate\Database\Seeder;

/**
 * The default ranking boards (A.9c).
 *
 * Base chain: the app's rankings screen needs rules to exist. Wealth and charm are the two
 * that can be computed today — room and agency boards need modules that do not exist yet,
 * so they are deliberately not seeded rather than seeded and permanently empty.
 *
 * ⚠ CI-02 informs the thresholds; these are placeholders.
 */
class RankingRuleSeeder extends Seeder
{
    /** [key, board, period, metric, min threshold, top N] */
    public const RULES = [
        ['wealth_daily', 'wealth', 'daily', 'coins_spent', 1000, 100],
        ['wealth_weekly', 'wealth', 'weekly', 'coins_spent', 5000, 100],
        ['charm_daily', 'charm', 'daily', 'diamonds_earned', 500, 100],
        ['charm_weekly', 'charm', 'weekly', 'diamonds_earned', 2500, 100],
    ];

    public function run(): void
    {
        foreach (self::RULES as [$key, $board, $period, $metric, $threshold, $topN]) {
            RankingRule::updateOrCreate(
                ['key' => $key],
                [
                    'board_type'    => $board,
                    'period'        => $period,
                    'metric'        => $metric,
                    'min_threshold' => $threshold,
                    'top_n'         => $topN,
                    'is_active'     => true,
                ],
            );
        }

        $this->command->info('Ranking rules: '.count(self::RULES).' seeded (wealth + charm; room and agency need later modules).');
    }
}
