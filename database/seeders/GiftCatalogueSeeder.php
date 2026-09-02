<?php

namespace Database\Seeders;

use App\Models\Gift;
use App\Models\GiftCategory;
use App\Models\VipTier;
use Illuminate\Database\Seeder;

/**
 * The starting gift catalogue (A.6a, A.6b).
 *
 * Base chain, like the room catalogue: the app cannot show gifts nobody defined.
 *
 * ⚠ **CI-06 — artwork and animations are a client input.** Every gift here has real
 * pricing and structure but no `animation_url`, because inventing asset paths that point
 * at nothing would look like working data and fail silently in the app. The panel shows
 * "no animation" for these, which is true.
 *
 * `diamond_value` is deliberately below `coin_price` — the spread is the platform's margin
 * on gifting, and the ratio is a business input (CI-02).
 */
class GiftCatalogueSeeder extends Seeder
{
    /** [key, English, Hindi, sort] */
    public const CATEGORIES = [
        ['popular', 'Popular', 'लोकप्रिय', 10],
        ['love', 'Love', 'प्यार', 20],
        ['festive', 'Festive', 'त्योहार', 30],
        ['luxury', 'Luxury', 'लक्ज़री', 40],
        ['fun', 'Fun', 'मज़ेदार', 50],
    ];

    /** [code, English, Hindi, category, tier, coins, diamonds, fullscreen] */
    public const GIFTS = [
        ['rose', 'Rose', 'गुलाब', 'love', 'basic', 10, 5, false],
        ['heart', 'Heart', 'दिल', 'love', 'basic', 50, 25, false],
        ['chai', 'Cutting Chai', 'कटिंग चाय', 'fun', 'basic', 20, 10, false],
        ['laddoo', 'Laddoo', 'लड्डू', 'festive', 'basic', 30, 15, false],
        ['diya', 'Diya', 'दीया', 'festive', 'premium', 199, 100, false],
        ['dhol', 'Dhol', 'ढोल', 'festive', 'premium', 499, 250, false],
        ['guitar', 'Guitar', 'गिटार', 'popular', 'premium', 999, 500, false],
        ['crown', 'Crown', 'ताज', 'luxury', 'luxury', 4999, 2500, true],
        ['sports_car', 'Sports Car', 'स्पोर्ट्स कार', 'luxury', 'luxury', 9999, 5000, true],
        ['private_jet', 'Private Jet', 'निजी जेट', 'luxury', 'legendary', 49999, 25000, true],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as [$key, $en, $hi, $sort]) {
            GiftCategory::updateOrCreate(
                ['key' => $key],
                ['name_en' => $en, 'name_hi' => $hi, 'sort_order' => $sort, 'is_active' => true],
            );
        }

        $categoryIds = GiftCategory::query()->pluck('id', 'key');

        // The legendary gift is gated to the top tier, resolved by level rather than by a
        // guessed id.
        $topTierId = VipTier::query()->orderByDesc('level')->value('id');

        foreach (self::GIFTS as $index => [$code, $en, $hi, $category, $tier, $coins, $diamonds, $fullscreen]) {
            Gift::updateOrCreate(
                ['code' => $code],
                [
                    'name_en'              => $en,
                    'name_hi'              => $hi,
                    'category_id'          => $categoryIds[$category] ?? null,
                    'tier'                 => $tier,
                    'coin_price'           => $coins,
                    'diamond_value'        => $diamonds,
                    'is_fullscreen'        => $fullscreen,
                    'is_combo_enabled'     => ! $fullscreen,
                    'max_combo'            => $fullscreen ? 1 : 99,
                    'required_vip_tier_id' => $tier === 'legendary' ? $topTierId : null,
                    'is_limited'           => false,
                    'stock'                => null,          // NULL is unlimited; 0 is sold out
                    'is_active'            => true,
                    'sort_order'           => ($index + 1) * 10,
                ],
            );
        }

        $this->command->info(sprintf(
            'Gift catalogue: %d categories, %d gifts (⚠ CI-06 — no artwork yet).',
            count(self::CATEGORIES),
            count(self::GIFTS),
        ));
    }
}
