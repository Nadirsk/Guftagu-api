<?php

namespace Database\Seeders;

use App\Domain\Agency\HostEarningsRollup;
use App\Models\AdminUser;
use App\Models\Agency;
use App\Models\AgencyMember;
use App\Models\CommissionSlab;
use App\Models\Host;
use App\Models\HostApplication;
use App\Models\HostTarget;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Fixtures for epic A.8.
 *
 *     php artisan db:seed --class=DemoUsersSeeder
 *     php artisan db:seed --class=AgencySeeder
 *
 * Depends on DemoUsersSeeder, because a host with no diamond ledger behind them makes
 * every earnings and settlement screen look broken rather than empty. The seeder rebuilds
 * the earnings rollup at the end so the numbers on screen are real, derived figures rather
 * than invented ones.
 */
class AgencySeeder extends Seeder
{
    /** [name, status, commission_bp, has documents] */
    public const AGENCIES = [
        ['Mumbai Voice Collective', Agency::APPROVED, 1500, true],
        ['Delhi Night Talkers', Agency::APPROVED, 1200, true],
        ['Chennai Sound House', Agency::PENDING, 1000, true],
        ['Kolkata Adda Agency', Agency::PENDING, 1000, false],
        ['Jaipur Mic Drop', Agency::SUSPENDED, 800, true],
    ];

    /** Host incentive bands, keyed on achievement percentage (A.8b). */
    public const INCENTIVE_SLABS = [
        [0, 49, 0],
        [50, 74, 250],
        [75, 99, 500],
        [100, null, 1000],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->error('AgencySeeder refuses to run outside local/testing.');

            return;
        }

        $admin = AdminUser::query()->orderBy('id')->first();
        $users = User::query()->orderBy('id')->get();

        if ($users->count() < 5) {
            $this->command->error('Run DemoUsersSeeder first — hosts need real users and a real ledger.');

            return;
        }

        $this->seedIncentiveSlabs($admin);

        $agencies = [];

        foreach (self::AGENCIES as $index => [$name, $status, $commissionBp, $hasDocs]) {
            $agency = Agency::query()->firstOrCreate(
                ['name' => $name],
                [
                    'code'          => Agency::nextCode(),
                    'owner_user_id' => $users[$index % $users->count()]->id,
                    'description'   => 'Runs late-night music and chat rooms.',
                    'contact_phone' => '+9198100'.str_pad((string) $index, 5, '0', STR_PAD_LEFT),
                    'contact_email' => 'owner'.$index.'@agency.example.com',
                    'documents'     => $hasDocs ? [
                        ['type' => 'gst', 'url' => 'https://cdn.example.com/docs/gst-'.$index.'.pdf', 'uploaded_at' => now()->subDays(20)->toIso8601ZuluString()],
                        ['type' => 'pan', 'url' => 'https://cdn.example.com/docs/pan-'.$index.'.pdf', 'uploaded_at' => now()->subDays(20)->toIso8601ZuluString()],
                    ] : null,
                    'commission_bp' => $commissionBp,
                    'status'        => $status,
                    'approved_by'   => $status === Agency::APPROVED ? $admin?->id : null,
                    'approved_at'   => $status === Agency::APPROVED ? now()->subDays(15) : null,
                    'managed_by'    => $admin?->id,
                ],
            );

            $agencies[] = $agency;
        }

        $this->command->info(count($agencies).' agencies in place.');

        // Hosts: the first four users, spread across the two approved agencies, plus one
        // with no agency at all so the unassigned filter has something to find.
        $approved = collect($agencies)->where('status', Agency::APPROVED)->values();
        $hostCount = 0;

        foreach ($users->take(5) as $index => $user) {
            $agency = $index < 4 ? $approved[$index % $approved->count()] : null;

            $host = Host::query()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'agency_id'          => $agency?->id,
                    'status'             => Host::APPROVED,
                    'applied_at'         => now()->subDays(40),
                    'approved_by'        => $admin?->id,
                    'approved_at'        => now()->subDays(38),
                    'tier'               => ['bronze', 'silver', 'gold'][$index % 3],
                    'base_commission_bp' => 500,
                    'contract_start'     => now()->subDays(38)->toDateString(),
                    // One contract already expired, so `under_contract` has a false case.
                    'contract_end'       => $index === 3 ? now()->subDays(2)->toDateString() : null,
                ],
            );

            if ($agency !== null && ! AgencyMember::where('user_id', $user->id)->where('is_active', true)->exists()) {
                AgencyMember::create([
                    'agency_id' => $agency->id,
                    'user_id'   => $user->id,
                    'role'      => 'host',
                    'joined_at' => now()->subDays(38),
                    'is_active' => true,
                ]);
            }

            // A target covering this month, so progress bars have something to show.
            HostTarget::query()->firstOrCreate(
                ['host_id' => $host->id, 'period_start' => now()->startOfMonth()->toDateString()],
                [
                    'period_end'      => now()->endOfMonth()->toDateString(),
                    'target_diamonds' => [100000, 50000, 25000, 10000, 5000][$index],
                    'target_days'     => 20,
                    'status'          => HostTarget::ACTIVE,
                    'created_by'      => $admin?->id,
                ],
            );

            $hostCount++;
        }

        // Pending applications for the approval queue.
        foreach ($users->skip(5)->take(3) as $index => $user) {
            HostApplication::query()->firstOrCreate(
                ['user_id' => $user->id, 'status' => HostApplication::PENDING],
                [
                    'agency_id'       => $approved[$index % $approved->count()]->id,
                    'intro_audio_url' => 'https://cdn.example.com/intros/'.$user->id.'.m4a',
                    'experience'      => 'Two years hosting music rooms on another app.',
                    'created_at'      => now()->subDays(random_int(1, 9)),
                ],
            );
        }

        $this->command->info($hostCount.' hosts and 3 pending applications seeded.');

        // Rebuild the rollup so every earnings figure on screen is derived from the ledger
        // DemoUsersSeeder wrote, not invented here.
        $rollup = app(HostEarningsRollup::class);
        $results = $rollup->forRange(now()->subDays(35), now());
        $days = count(array_filter($results, fn ($r) => $r['hosts'] > 0));

        $this->command->info("Earnings rolled up: {$days} days with activity.");
        $this->command->info('Generate a settlement from the panel, or with SettlementService.');
    }

    /**
     * Incentive bands are commission slabs with `applies_to = host`, keyed on achievement
     * percentage rather than on a money amount — that is what A.8b asks for.
     */
    protected function seedIncentiveSlabs(?AdminUser $admin): void
    {
        foreach (self::INCENTIVE_SLABS as [$min, $max, $bp]) {
            CommissionSlab::query()->firstOrCreate(
                [
                    'applies_to' => 'host',
                    'metric'     => 'diamonds_earned',
                    'min_value'  => $min,
                    'max_value'  => $max,
                ],
                [
                    'percentage_bp'  => $bp,
                    'effective_from' => now()->subYear(),
                    'created_by'     => $admin?->id,
                ],
            );
        }
    }
}
