<?php

namespace Database\Seeders;

use App\Domain\Economy\RateResolver;
use App\Models\CommissionSlab;
use App\Models\ConversionRate;
use App\Models\RechargePackage;
use Illuminate\Database\Seeder;

/**
 * Opening economy configuration (A.7a, A.7c).
 *
 * Base chain: without a diamond→INR rate no withdrawal can be priced at all, so an empty
 * table is a broken platform rather than a clean slate.
 *
 * ⚠ **CI-01 and CI-02 — every number here is a placeholder.** Coin pricing, the
 * coin↔diamond rate, the diamond→INR rate and commission slabs are client inputs the SoW
 * does not contain. They are seeded so the platform is operable and the panel is testable;
 * all of them live in tables precisely so the real values need no code change.
 */
class EconomySeeder extends Seeder
{
    public function run(): void
    {
        $rates = app(RateResolver::class);

        // Rational, not decimal. 2 coins → 1 diamond; 1 diamond → 50 paise (₹0.50).
        // Backdated deliberately. A rate effective from `now()` means nothing that happened
        // before this seeder ran can be priced at all, so every historical rollup would
        // honestly report zero rupees against real diamonds — which reads as a broken
        // screen rather than as a missing rate. Two years is well before any seeded data.
        $genesis = now()->subYears(2)->startOfYear();

        if (ConversionRate::query()->where('key', RateResolver::COIN_TO_DIAMOND)->doesntExist()) {
            $rates->set(RateResolver::COIN_TO_DIAMOND, 1, 2, from: $genesis, note: 'Placeholder — CI-01');
        }

        if (ConversionRate::query()->where('key', RateResolver::DIAMOND_TO_INR)->doesntExist()) {
            $rates->set(RateResolver::DIAMOND_TO_INR, 50, 1, from: $genesis, note: 'Placeholder — CI-01. Paise per diamond.');
        }

        // [name, coins, bonus, ₹, badge, first-purchase-only]
        $packages = [
            ['Starter', 100, 0, 99, null, false],
            ['Popular', 500, 50, 449, 'Best value', false],
            ['Pro', 1200, 200, 999, null, false],
            ['Mega', 5000, 1200, 3999, 'Most coins', false],
            ['First-timer bonus', 300, 300, 199, 'Double coins', true],
        ];

        foreach ($packages as $index => [$name, $coins, $bonus, $rupees, $badge, $firstOnly]) {
            RechargePackage::updateOrCreate(
                ['name' => $name],
                [
                    'coins'                  => $coins,
                    'bonus_coins'            => $bonus,
                    'price_paise'            => $rupees * 100,
                    'is_first_purchase_only' => $firstOnly,
                    'badge_text'             => $badge,
                    'is_active'              => true,
                    'sort_order'             => ($index + 1) * 10,
                ],
            );
        }

        // Non-overlapping by construction — the API refuses overlaps, and so should this.
        // [min, max, basis points]
        $slabs = [
            [0, 100000, 3000],          // 30% up to 100k diamonds earned
            [100000, 1000000, 2500],    // 25%
            [1000000, null, 2000],      // 20% and above
        ];

        foreach ($slabs as [$min, $max, $bp]) {
            $exists = CommissionSlab::query()
                ->where('applies_to', 'platform')
                ->where('metric', 'diamonds_earned')
                ->where('min_value', $min)
                ->exists();

            if (! $exists) {
                CommissionSlab::create([
                    'applies_to'     => 'platform',
                    'metric'         => 'diamonds_earned',
                    'min_value'      => $min,
                    'max_value'      => $max,
                    'percentage_bp'  => $bp,
                    // Backdated for the same reason as the rates above: a slab effective
                    // from now() covers nothing that already happened, and the rollup
                    // refuses to price a day it has no platform rate for.
                    'effective_from' => $genesis,
                ]);
            }
        }

        $this->command->info('Economy: 2 rates, '.count($packages).' packages, '.count($slabs).' slabs (⚠ CI-01/CI-02 — placeholders).');
    }
}
