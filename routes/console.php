<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
| Run by `php artisan schedule:work` locally, or a single cron entry calling
| `schedule:run` every minute in production (docs/07).
*/

// GFT-018 — the dashboard reads this, so it has to be current before anyone looks.
Schedule::command('stats:rollup')->dailyAt('00:20')->withoutOverlapping();

// GFT-074 — docs/02 §15 rule 12. Runs after the rollup so a mismatch is not blamed on
// half-written statistics.
Schedule::command('economy:reconcile --quiet-on-pass')->dailyAt('00:40')->withoutOverlapping();

// A.5d — closes out lapsed sanctions and records the expiry. The release itself is
// derived in User::effectiveStatus(), so a missed run delays the log entry, never the
// user getting their account back.
Schedule::command('moderation:expire-sanctions --quiet-on-none')->everyFifteenMinutes()->withoutOverlapping();

// A.8c - rebuild host earnings from the diamond ledger, then close out any target whose
// period has ended. Order matters: evaluating before the final day is rolled up would
// freeze an incentive that is short by a day.
Schedule::command('hosts:rollup-earnings')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('hosts:evaluate-targets --quiet-on-none')->dailyAt('01:30')->withoutOverlapping();
