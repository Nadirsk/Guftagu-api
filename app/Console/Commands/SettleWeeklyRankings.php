<?php

namespace App\Console\Commands;

use App\Domain\Events\EventException;
use App\Domain\Events\LeaderboardService;
use App\Models\LeaderboardSnapshot;
use App\Models\RankingRule;
use Illuminate\Console\Command;

/**
 * Rich / Charm Weekly List — "week ke end mein Top 3 ko rewards automatically mil jayein".
 *
 * Once a week has closed, its board is frozen and the rewards the admin set for each rank
 * are paid. The admin panel's Snapshot / Pay buttons stay as the manual path; both go
 * through the same service, and a payout is unique per (snapshot, user, reward), so
 * running this twice, or after a manual pay, pays nothing further.
 *
 * Only the week that has just ended is handled. A snapshot, once taken, is never redone
 * here, so a week's record cannot drift after the fact.
 */
class SettleWeeklyRankings extends Command
{
    protected $signature = 'rankings:settle-weekly {--quiet-on-none : Print nothing when nothing was paid}';

    protected $description = 'Freeze last week\'s Rich and Charm boards and pay their rank rewards';

    public function handle(LeaderboardService $leaderboards): int
    {
        $lastWeek = now()->subWeek();
        $paidAny = false;

        $rules = RankingRule::query()
            ->where('period', 'weekly')
            ->where('is_active', true)
            ->whereIn('board_type', RankingRule::COMPUTABLE_BOARDS) // CP has no standings to freeze or pay
            ->get();

        foreach ($rules as $rule) {
            [$start, $end] = $leaderboards->periodFor($rule, $lastWeek);

            $hasSnapshot = LeaderboardSnapshot::query()
                ->where('rule_key', $rule->key)
                ->where('period_start', $start->toDateString())
                ->exists();

            if (! $hasSnapshot) {
                $leaderboards->snapshot($rule, $start, $end);
            }

            try {
                $result = $leaderboards->payRewards($rule, $start);
            } catch (EventException) {
                continue; // nobody made the board that week — nothing to pay
            }

            if ($result['paid'] > 0) {
                $paidAny = true;
                $this->info(sprintf('%s — week of %s: %d rewards paid.', $rule->key, $start->toDateString(), $result['paid']));
            }
        }

        if (! $paidAny && ! $this->option('quiet-on-none')) {
            $this->info('No ranking rewards were due.');
        }

        return self::SUCCESS;
    }
}
