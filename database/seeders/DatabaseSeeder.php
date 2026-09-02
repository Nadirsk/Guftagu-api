<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Order matters: permissions before roles (baselines resolve keys to ids),
     * roles before the Super Admin (it needs a role_id).
     *
     * Every seeder here is idempotent — `db:seed` can be re-run safely.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            SettingsSeeder::class,
            // Rates before anything that prices something.
            EconomySeeder::class,
            // VIP tiers before gifts, frames, effects and room themes — they all point at one.
            VipTierSeeder::class,
            GiftCatalogueSeeder::class,
            RoomCatalogueSeeder::class,
            RankingRuleSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
