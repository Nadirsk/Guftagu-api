<?php

namespace Database\Seeders;

use App\Models\RoomCategory;
use App\Models\RoomTheme;
use Illuminate\Database\Seeder;

/**
 * The starting room categories and themes (A.4d).
 *
 * Unlike the demo seeders, this one IS safe in production and belongs in DatabaseSeeder:
 * the mobile app cannot offer a category that was never defined, so an empty catalogue is
 * a broken app rather than a clean slate. Names are bilingual because the app ships en + hi.
 *
 * Idempotent — re-running updates the display fields and leaves ids alone, so rooms keep
 * pointing at the same category.
 */
class RoomCatalogueSeeder extends Seeder
{
    /** [key, English, Hindi, sort] */
    public const CATEGORIES = [
        ['music', 'Music', 'संगीत', 10],
        ['chat', 'Just Chatting', 'बातचीत', 20],
        ['comedy', 'Comedy', 'कॉमेडी', 30],
        ['gaming', 'Gaming', 'गेमिंग', 40],
        ['dating', 'Dating', 'डेटिंग', 50],
        ['devotional', 'Devotional', 'भक्ति', 60],
        ['news', 'News & Talk', 'समाचार', 70],
        ['study', 'Study Room', 'अध्ययन', 80],
    ];

    /** [name, premium, vip tier, coin price] */
    public const THEMES = [
        ['Midnight Studio', false, null, 0],
        ['Neon Bazaar', false, null, 0],
        ['Monsoon', false, null, 0],
        ['Gold Lounge', true, 2, 5000],
        ['Royal Durbar', true, 3, 12000],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as [$key, $en, $hi, $sort]) {
            RoomCategory::updateOrCreate(
                ['key' => $key],
                ['name_en' => $en, 'name_hi' => $hi, 'sort_order' => $sort, 'is_active' => true],
            );
        }

        // The THEMES table lists a VIP *level*; the column stores a tier *id*. Those only
        // coincide by accident, and there is now a foreign key, so resolve it properly.
        // A level with no tier yet leaves the gate unset rather than failing the insert.
        $tierIdByLevel = \App\Models\VipTier::query()->pluck('id', 'level');

        foreach (self::THEMES as [$name, $premium, $level, $price]) {
            RoomTheme::updateOrCreate(
                ['name' => $name],
                [
                    'is_premium'           => $premium,
                    'required_vip_tier_id' => $level === null ? null : ($tierIdByLevel[$level] ?? null),
                    'coin_price'           => $price,
                    'is_active'            => true,
                ],
            );
        }

        $this->command->info(sprintf(
            'Room catalogue: %d categories, %d themes.',
            count(self::CATEGORIES),
            count(self::THEMES),
        ));
    }
}
