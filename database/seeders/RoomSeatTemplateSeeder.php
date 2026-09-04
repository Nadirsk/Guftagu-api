<?php

namespace Database\Seeders;

use App\Models\RoomSeatTemplate;
use Illuminate\Database\Seeder;

/** A starter set of seat layouts, the same reference-data-before-a-consumer pattern as RankingRuleSeeder / WealthCharmLevelSeeder. */
class RoomSeatTemplateSeeder extends Seeder
{
    /** [name, total_seats, vip_positions] */
    public const TEMPLATES = [
        ['8 seats — no VIP', 8, []],
        ['8 seats — 1 VIP', 8, [1]],
        ['9 seats — 3 VIP (podium)', 9, [1, 2, 3]],
        ['12 seats — 2 VIP', 12, [1, 2]],
        ['15 seats — 3 VIP', 15, [1, 2, 3]],
    ];

    public function run(): void
    {
        foreach (self::TEMPLATES as [$name, $totalSeats, $vipPositions]) {
            RoomSeatTemplate::updateOrCreate(
                ['name' => $name],
                ['total_seats' => $totalSeats, 'vip_positions' => $vipPositions, 'is_active' => true],
            );
        }

        $this->command->info('Room seat templates: '.count(self::TEMPLATES).' seeded.');
    }
}
