<?php

namespace Database\Seeders;

use App\Models\CoinTransaction;
use App\Models\Device;
use App\Models\DiamondTransaction;
use App\Models\User;
use App\Models\UserKyc;
use App\Models\UserProfile;
use App\Models\UserSanction;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * App-user fixtures for exercising epic A.3.
 *
 * Local/testing only, and deliberately not part of DatabaseSeeder — these are invented
 * people with invented balances and must never appear in a real environment.
 *
 *     php artisan db:seed --class=DemoUsersSeeder
 *
 * Ledger rows are written properly (balance_before/balance_after chained) rather than
 * having balances poked into `wallets`, so the integrity check has something true to
 * verify and the transaction list looks like a real history.
 */
class DemoUsersSeeder extends Seeder
{
    /** [display name, phone, country, city, status, coins, diamonds, kyc status] */
    public const PEOPLE = [
        ['Aarav Sharma', '+919820011221', 'India', 'Mumbai', 'active', 12500, 3200, 'verified'],
        ['Diya Nair', '+919820022332', 'India', 'Kochi', 'active', 4800, 15400, 'verified'],
        ['Rohan Mehta', '+919820033443', 'India', 'Pune', 'active', 250, 0, 'pending'],
        ['Ishaan Gupta', '+919820044554', 'India', 'Delhi', 'suspended', 900, 120, 'rejected'],
        ['Ananya Rao', '+919820055665', 'India', 'Bengaluru', 'active', 76000, 41000, 'verified'],
        ['Kabir Khan', '+919820066776', 'India', 'Hyderabad', 'banned', 0, 0, 'none'],
        ['Meera Iyer', '+919820077887', 'India', 'Chennai', 'active', 3100, 640, 'pending'],
        ['Vivaan Joshi', '+919820088998', 'India', 'Ahmedabad', 'active', 15, 0, 'none'],
    ];

    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->command->error('DemoUsersSeeder refuses to run outside local/testing.');

            return;
        }

        foreach (self::PEOPLE as $index => [$name, $phone, $country, $city, $status, $coins, $diamonds, $kyc]) {
            if (User::query()->where('phone_hash', User::hash($phone))->exists()) {
                $this->command->warn("{$name} already exists — left alone.");

                continue;
            }

            DB::transaction(function () use ($index, $name, $phone, $country, $city, $status, $coins, $diamonds, $kyc) {
                $user = User::create([
                    'guftagu_id' => 'GF' . str_pad((string) (8420100 + $index), 7, '0', STR_PAD_LEFT),
                    'phone' => $phone,
                    'country_code' => '+91',
                    'email' => str(strtolower($name))->replace(' ', '.')->value() . '@example.com',
                    'status' => $status,
                    'agora_uid' => 100000 + $index,
                    'last_active_at' => now()->subMinutes(random_int(2, 6000)),
                    'registered_ip' => '203.0.113.' . (10 + $index),
                    'consent_version' => '1.0',
                    'consent_at' => now()->subDays(random_int(5, 400)),
                    'created_at' => now()->subDays(random_int(5, 400)),
                ]);

                UserProfile::create([
                    'user_id' => $user->id,
                    'display_name' => $name,
                    'bio' => 'Here for the late-night music rooms.',
                    'gender' => $index % 2 === 0 ? 'male' : 'female',
                    'date_of_birth' => now()->subYears(random_int(19, 38))->toDateString(),
                    'country' => $country,
                    'city' => $city,
                    'language' => $index % 3 === 0 ? 'hi' : 'en',
                    'is_profile_complete' => true,
                ]);

                $wallet = Wallet::create([
                    'user_id' => $user->id,
                    'lifetime_coins_purchased' => $coins,
                    // Wealth rank is lifetime coins **spent**, not purchased (docs/02 §7).
                    // Someone who bought coins has plausibly spent most of them, and
                    // leaving this at zero makes every wealth leaderboard permanently
                    // empty — which looks like a broken board rather than quiet users.
                    'lifetime_coins_spent' => (int) round($coins * 0.7),
                    'lifetime_diamonds_earned' => $diamonds,
                ]);

                $this->seedLedger($wallet, $user, CoinTransaction::class, 'coin_balance', $coins, 'recharge');
                $this->seedLedger($wallet, $user, DiamondTransaction::class, 'diamond_balance', $diamonds, 'gift_received');

                if ($kyc !== 'none') {
                    UserKyc::create([
                        'user_id' => $user->id,
                        'full_name' => $name,
                        'doc_type' => 'aadhaar',
                        'doc_number' => '9876' . str_pad((string) $index, 8, '0', STR_PAD_LEFT),
                        'doc_front_url' => "https://placehold.co/800x500?text=Aadhaar+Front+{$index}",
                        'doc_back_url' => "https://placehold.co/800x500?text=Aadhaar+Back+{$index}",
                        'selfie_url' => "https://placehold.co/500x500?text=Selfie+{$index}",
                        'ifsc' => 'HDFC0001234',
                        'upi_id' => str(strtolower($name))->replace(' ', '')->value() . '@upi',
                        'status' => $kyc,
                        'rejection_reason' => $kyc === 'rejected' ? 'Document photo was unreadable.' : null,
                        'created_at' => now()->subDays(random_int(1, 30)),
                    ]);
                }

                Device::create([
                    'user_id' => $user->id,
                    'device_id' => 'demo-device-' . $user->id,
                    'platform' => $index % 2 === 0 ? 'android' : 'ios',
                    'app_version' => '1.0.' . random_int(0, 9),
                    'os_version' => $index % 2 === 0 ? 'Android 14' : 'iOS 18.2',
                    'last_seen_at' => now()->subMinutes(random_int(2, 6000)),
                ]);

                if ($status !== 'active') {
                    UserSanction::create([
                        'user_id' => $user->id,
                        'type' => $status === 'banned' ? UserSanction::PERMANENT_BAN : UserSanction::TEMP_BAN,
                        'reason' => $status === 'banned'
                            ? 'Repeated harassment in voice rooms after two warnings.'
                            : 'Spam links in room chat.',
                        'starts_at' => now()->subDays(3),
                        'expires_at' => $status === 'banned' ? null : now()->addDays(4),
                        'is_active' => true,
                    ]);
                }
            });

            $this->command->info(sprintf('%-16s %-15s %-10s %s', $name, $phone, $status, $kyc));
        }

        $this->command->info('Search by full phone (e.g. 9820011221) — partial numbers cannot match encrypted columns.');
    }

    /**
     * Writes a small, correctly chained history rather than one lump, so the ledger view
     * has something to show and the integrity check is actually exercising a chain.
     *
     * @param  class-string<\App\Models\LedgerTransaction>  $model
     */
    protected function seedLedger(Wallet $wallet, User $user, string $model, string $column, int $target, string $type): void
    {
        if ($target <= 0) {
            return;
        }

        // Three uneven slices that add up to exactly the target — no rounding drift.
        $first = intdiv($target, 2);
        $second = intdiv($target, 3);
        $slices = array_filter([$first, $second, $target - $first - $second]);

        $balance = 0;

        foreach ($slices as $offset => $amount) {
            $model::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'direction' => 'credit',
                'amount' => $amount,
                'balance_before' => $balance,
                'balance_after' => $balance + $amount,
                'type' => $type,
                'created_at' => now()->subDays(30 - ($offset * 9)),
            ]);

            $balance += $amount;
        }

        $wallet->forceFill([$column => $balance, 'version' => $wallet->version + 1])->save();
    }
}
