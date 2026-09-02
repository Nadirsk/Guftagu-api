<?php

namespace Database\Seeders;

use App\Domain\Economy\WithdrawalService;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Database\Seeder;

/**
 * Demo payout requests, so the review queue has something to review.
 *
 * Local/testing only. Nothing creates withdrawals until the mobile app does (D.6d), and an
 * empty queue cannot show whether approve, reject, the frozen-diamond handling or the
 * second-approval rule actually work.
 *
 * Raised through WithdrawalService rather than inserted directly, so the diamonds really
 * are frozen and the wallet state is honest.
 *
 *     php artisan db:seed --class=DemoWithdrawalsSeeder
 */
class DemoWithdrawalsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->error('DemoWithdrawalsSeeder refuses to run outside local/testing.');

            return;
        }

        $service = app(WithdrawalService::class);

        // Only people who actually hold diamonds can raise one.
        $candidates = Wallet::query()
            ->where('diamond_balance', '>', 2000)
            ->orderByDesc('diamond_balance')
            ->limit(4)
            ->get();

        if ($candidates->isEmpty()) {
            $this->command->error('No user holds enough diamonds — run DemoUsersSeeder first.');

            return;
        }

        // A deliberate spread: a couple of ordinary requests and one comfortably over the
        // ₹50,000 second-approval threshold, so that path is exercisable.
        $amounts = [2000, 5000, 120000, 3000];

        foreach ($candidates as $index => $wallet) {
            $user = User::find($wallet->user_id);
            $diamonds = min($amounts[$index] ?? 2000, $wallet->availableOf(Wallet::DIAMOND));

            if ($diamonds < 1000) {
                continue;
            }

            if (Withdrawal::query()->where('user_id', $user->id)->exists()) {
                $this->command->warn("{$user->guftagu_id} already has a request — left alone.");

                continue;
            }

            $withdrawal = $service->request($user, $diamonds);

            $this->command->info(sprintf(
                '%-12s %8s diamonds  ->  Rs %s%s',
                $user->guftagu_id,
                number_format($withdrawal->diamonds),
                number_format($withdrawal->net_paise / 100, 2),
                $withdrawal->net_paise >= 5000000 ? '   (needs a Super Admin)' : '',
            ));
        }

        $this->command->info('Raised through the service, so the diamonds are genuinely frozen.');
    }
}
