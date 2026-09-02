<?php

namespace Database\Seeders;

use App\Models\VipTier;
use Illuminate\Database\Seeder;

/**
 * The VIP ladder (A.6c).
 *
 * Part of the base chain, not a demo seeder: gifts, frames, effects and room themes all
 * reference a tier, so an empty table makes those gates unconfigurable.
 *
 * ⚠ **CI-02 — the prices here are placeholders.** Real tier pricing is a client input the
 * SoW does not contain (docs/00 §7). They are seeded so the shape exists and the panel can
 * be used; every one is expected to change, which is exactly why they live in a table
 * rather than in code.
 *
 * Idempotent by `level`, so re-running keeps ids stable and nothing pointing at a tier
 * breaks.
 */
class VipTierSeeder extends Seeder
{
    /** [level, English, Hindi, monthly ₹, privileges] */
    public const TIERS = [
        [1, 'VIP Bronze', 'वीआईपी ब्रॉन्ज़', 199, ['ad_free', 'chat_colour']],
        [2, 'VIP Silver', 'वीआईपी सिल्वर', 499, ['ad_free', 'chat_colour', 'profile_frame', 'priority_seat']],
        [3, 'VIP Gold', 'वीआईपी गोल्ड', 999, ['ad_free', 'chat_colour', 'profile_frame', 'priority_seat', 'entrance_effect', 'exclusive_gifts', 'custom_room_theme']],
        [4, 'VIP Diamond', 'वीआईपी डायमंड', 2499, ['ad_free', 'chat_colour', 'profile_frame', 'priority_seat', 'entrance_effect', 'exclusive_gifts', 'custom_room_theme', 'hidden_entry', 'anti_kick', 'rank_boost']],
    ];

    public function run(): void
    {
        foreach (self::TIERS as [$level, $en, $hi, $monthlyRupees, $privileges]) {
            $monthly = $monthlyRupees * 100;   // stored as paise, never rupees

            VipTier::updateOrCreate(
                ['level' => $level],
                [
                    'name_en'               => $en,
                    'name_hi'               => $hi,
                    'monthly_price_paise'   => $monthly,
                    // Conventional discounts; also placeholders pending CI-02.
                    'quarterly_price_paise' => (int) round($monthly * 3 * 0.9),
                    'yearly_price_paise'    => (int) round($monthly * 12 * 0.75),
                    'privileges'            => $privileges,
                    'is_active'             => true,
                ],
            );
        }

        $this->command->info(sprintf('VIP tiers: %d seeded (⚠ CI-02 — prices are placeholders).', count(self::TIERS)));
    }
}
