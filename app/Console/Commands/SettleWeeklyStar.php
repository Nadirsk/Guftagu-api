<?php

namespace App\Console\Commands;

use App\Domain\Events\EventCampaignService;
use App\Models\Event;
use Illuminate\Console\Command;

/**
 * Weekly Star — "week ke end mein automatically top 3 users ko woh rewards mil jayenga".
 *
 * Every finished round of a published, gift-scored Weekly Star that has no `event_rounds`
 * record yet is ranked and paid. Catching up is deliberate: a scheduler that was down for
 * a fortnight pays both missed rounds on its next run, each against its own window. A
 * re-run pays nothing twice — claims are unique per (tier, user, round).
 */
class SettleWeeklyStar extends Command
{
    protected $signature = 'events:settle-weekly-star {--quiet-on-none : Print nothing when no round is due}';

    protected $description = 'Pay the Top 3 of every Weekly Star round that has finished';

    public function handle(EventCampaignService $campaigns): int
    {
        $events = Event::query()
            ->where('type', Event::WEEKLY_STAR)
            ->whereNull('ranking_rule_key')
            ->where('status', Event::SCHEDULED)
            ->where('starts_at', '<=', now())
            ->get();

        $settled = 0;

        foreach ($events as $event) {
            foreach ($campaigns->dueRounds($event) as $roundStart) {
                $round = $campaigns->settleRound($event, $roundStart);
                $settled++;

                $this->info(sprintf(
                    '%s — round of %s: %d %s paid.',
                    $event->title_en,
                    $roundStart->toDateString(),
                    $round->granted,
                    str('place')->plural($round->granted),
                ));
            }
        }

        if ($settled === 0 && ! $this->option('quiet-on-none')) {
            $this->info('No Weekly Star round is due.');
        }

        return self::SUCCESS;
    }
}
