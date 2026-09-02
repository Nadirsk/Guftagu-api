<?php

namespace App\Console\Commands;

use App\Domain\Agency\AgencyException;
use App\Domain\Agency\TargetService;
use App\Models\HostTarget;
use Illuminate\Console\Command;

/**
 * GFT-082 — close out targets whose period has ended (A.8b).
 *
 * Only the *freeze* happens here. Live progress is derived from `host_earnings` at read
 * time, so a missed run delays the incentive being locked in, never the panel showing the
 * right number.
 *
 * Deliberately runs after `hosts:rollup-earnings`: evaluating a target before the final
 * day has been rolled up would freeze an incentive that is short by a day.
 */
class EvaluateHostTargets extends Command
{
    protected $signature = 'hosts:evaluate-targets {--quiet-on-none : Print nothing when there is nothing due}';

    protected $description = 'Freeze achievement and incentive for targets whose period has ended';

    public function handle(TargetService $targets): int
    {
        $due = HostTarget::query()->dueForEvaluation()->with('host')->get();

        if ($due->isEmpty()) {
            if (! $this->option('quiet-on-none')) {
                $this->info('No targets due for evaluation.');
            }

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($due as $target) {
            try {
                $evaluated = $targets->evaluate($target);

                $rows[] = [
                    $evaluated->host_id,
                    $evaluated->period_start->toDateString(),
                    $evaluated->achievement_pct.'%',
                    $evaluated->status,
                    number_format($evaluated->incentive_paise),
                ];
            } catch (AgencyException $e) {
                // One bad target must not stop the rest of the month closing.
                $this->warn("Target #{$target->id}: {$e->getMessage()}");
            }
        }

        $this->table(['Host', 'Period', 'Achieved', 'Result', 'Incentive (paise)'], $rows);

        return self::SUCCESS;
    }
}
