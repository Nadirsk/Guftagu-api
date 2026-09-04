<?php

namespace Database\Seeders;

use App\Models\WealthCharmLevel;
use Illuminate\Database\Seeder;

/**
 * The default wealth/charm ladder (docs/00 §7). ⚠ CI-02 informs the real thresholds and
 * badge artwork; these are placeholders so the feature is usable before that lands, the
 * same stance VipTierSeeder and RankingRuleSeeder take.
 */
class WealthCharmLevelSeeder extends Seeder
{
    /** [type, level, name_en, threshold] */
    public const LEVELS = [
        ['wealth', 1, 'Wealth I',   0],
        ['wealth', 2, 'Wealth II',   10_000],
        ['wealth', 3, 'Wealth III',  50_000],
        ['wealth', 4, 'Wealth IV',   200_000],
        ['wealth', 5, 'Wealth V',    1_000_000],
        ['charm', 1, 'Charm I',   0],
        ['charm', 2, 'Charm II',  5_000],
        ['charm', 3, 'Charm III', 25_000],
        ['charm', 4, 'Charm IV',  100_000],
        ['charm', 5, 'Charm V',   500_000],
    ];

    public function run(): void
    {
        foreach (self::LEVELS as [$type, $level, $name, $threshold]) {
            WealthCharmLevel::updateOrCreate(
                ['type' => $type, 'level' => $level],
                ['name_en' => $name, 'threshold' => $threshold, 'is_active' => true],
            );
        }

        $this->command->info('Wealth/charm levels: '.count(self::LEVELS).' seeded (5 wealth + 5 charm).');
    }
}
