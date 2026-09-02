<?php

namespace App\Console\Commands;

use App\Models\ModerationLog;
use App\Models\User;
use App\Models\UserSanction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A.5d — "the ban auto-expires without manual action, and both the application and the
 * expiry are logged."
 *
 * The *release* does not depend on this command: `User::effectiveStatus()` derives it from
 * whether any sanction is still active, so a lapsed ban stops biting the moment it lapses
 * even if nothing is scheduled. What this adds is the second half of the criterion — a
 * written record that the expiry happened — plus tidying `users.status` so the column and
 * the truth agree.
 *
 * Deriving first and reconciling second is the order that matters: the other way round, a
 * stalled scheduler silently keeps people locked out.
 */
class ExpireSanctions extends Command
{
    protected $signature = 'moderation:expire-sanctions {--quiet-on-none : Print nothing when there is nothing to expire}';

    protected $description = 'Close out lapsed sanctions, log the expiry, and release the accounts';

    public function handle(): int
    {
        $lapsed = UserSanction::query()
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with('user')
            ->get();

        if ($lapsed->isEmpty()) {
            if (! $this->option('quiet-on-none')) {
                $this->info('Nothing to expire.');
            }

            return self::SUCCESS;
        }

        $released = 0;

        foreach ($lapsed as $sanction) {
            DB::transaction(function () use ($sanction, &$released) {
                $sanction->forceFill(['is_active' => false])->save();

                ModerationLog::create([
                    'admin_user_id' => null,          // nobody did this; time did
                    'action'        => 'sanction_expired',
                    'target_type'   => User::class,
                    'target_id'     => (string) $sanction->user_id,
                    'before'        => ['type' => $sanction->type, 'expires_at' => $sanction->expires_at?->toIso8601ZuluString()],
                    'after'         => ['is_active' => false],
                    'reason'        => 'Sanction reached its end time.',
                ]);

                $user = $sanction->user;

                // Only release the account when nothing else is still holding it.
                if ($user !== null && $user->status !== User::STATUS_ACTIVE && ! $user->hasActiveSanction()) {
                    $user->forceFill(['status' => User::STATUS_ACTIVE])->save();
                    $released++;
                }
            });
        }

        $this->info(sprintf(
            '%d %s expired, %d %s released.',
            $lapsed->count(),
            str('sanction')->plural($lapsed->count()),
            $released,
            str('account')->plural($released),
        ));

        return self::SUCCESS;
    }
}
