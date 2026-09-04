<?php

namespace App\Domain\Agency;

use App\Domain\Audit\AuditLogger;
use App\Models\AdminUser;
use App\Models\CoinTransaction;
use App\Models\GiftTargetPolicy;
use App\Models\Host;
use App\Models\HostGiftTargetResult;
use App\Models\RoomMember;
use Illuminate\Support\Carbon;

/**
 * The monthly gift-target ladder (mehfil's "Policies" screen, ported — see
 * `gift_target_policies`'s migration for how this differs from A.8b's `HostTarget`).
 *
 * Evaluation is admin-triggered from the panel rather than a nightly cron, the same
 * choice already made for `SettlementService`: an explicit action that shows up in the
 * audit log, not a background process nobody watches run.
 */
class GiftTargetService
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    /**
     * Coins the host spent sending gifts, and minutes they spent live (seated as owner or
     * speaker), for a calendar month — read live, exactly like `TargetService::progress()`
     * reads live until a result is evaluated.
     *
     * @return array{coins_sent: int, minutes_live: int}
     */
    public function totals(Host $host, string $period): array
    {
        [$start, $end] = $this->periodBounds($period);

        $coinsSent = (int) CoinTransaction::query()
            ->where('user_id', $host->user_id)
            ->where('type', 'gift_sent')
            ->where('direction', CoinTransaction::DEBIT)
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $minutesLive = (int) floor(
            RoomMember::query()
                ->where('user_id', $host->user_id)
                ->whereIn('role', ['owner', 'speaker'])
                ->whereBetween('joined_at', [$start, $end])
                ->sum('duration_seconds') / 60
        );

        return ['coins_sent' => $coinsSent, 'minutes_live' => $minutesLive];
    }

    /**
     * Freeze one host's standing for a month against the ladder — the highest tier whose
     * both thresholds are cleared, same "first match wins because it's sorted" shape as
     * mehfil's own command.
     *
     * @throws AgencyException
     */
    public function evaluate(Host $host, string $period, AdminUser $actor): HostGiftTargetResult
    {
        $this->assertPeriodFormat($period);

        if (HostGiftTargetResult::where('host_id', $host->id)->where('period', $period)->whereNotNull('evaluated_at')->exists()) {
            throw new AgencyException('BAD_REQUEST', "That host's {$period} gift target has already been evaluated.", 400);
        }

        $totals = $this->totals($host, $period);

        $policy = GiftTargetPolicy::query()
            ->where('is_active', true)
            ->where('target_coins', '<=', $totals['coins_sent'])
            ->where('time_minutes', '<=', $totals['minutes_live'])
            ->orderByDesc('target_coins')
            ->first();

        $result = HostGiftTargetResult::updateOrCreate(
            ['host_id' => $host->id, 'period' => $period],
            [
                'coins_sent'          => $totals['coins_sent'],
                'minutes_live'        => $totals['minutes_live'],
                'policy_id'           => $policy?->id,
                'host_reward_paise'   => $policy?->host_reward_paise ?? 0,
                // No agency, no agency reward — the coins/minutes still count for the host.
                'agency_reward_paise' => ($policy !== null && $host->agency_id !== null) ? $policy->agency_reward_paise : 0,
                'evaluated_at'        => now(),
                'evaluated_by'        => $actor->id,
            ],
        );

        $this->audit->log($actor, 'host_gift_target.evaluate', 'agency', HostGiftTargetResult::class, $result->id, null, [
            'period' => $period, 'policy_id' => $policy?->id,
            'host_reward_paise' => $result->host_reward_paise, 'agency_reward_paise' => $result->agency_reward_paise,
        ]);

        return $result;
    }

    /**
     * Live progress toward the ladder — the read-only counterpart to `evaluate()`, the
     * same "derived while running, frozen once decided" split `TargetService::progress()`
     * uses for A.8b's targets. Never writes anything; safe to call every time a screen
     * renders.
     *
     * The "current" rung is the lowest one not yet fully cleared — what the host is
     * actually working toward right now. Once every rung is cleared, the highest one is
     * shown at (or past) 100%, so a maxed-out host still reads as "done", not "nothing
     * left to track".
     *
     * @return array{
     *   period: string, coins_sent: int, minutes_live: int,
     *   target: array{id: int, target_coins: int, time_minutes: int}|null,
     *   coins_pct: int|null, minutes_pct: int|null, overall_pct: int|null,
     * }
     */
    public function liveProgress(Host $host, ?string $period = null): array
    {
        $period ??= now()->format('Y-m');
        $this->assertPeriodFormat($period);

        $totals = $this->totals($host, $period);

        $rungs = GiftTargetPolicy::query()->where('is_active', true)->orderBy('target_coins')->get();

        $current = $rungs->first(
            fn (GiftTargetPolicy $p) => $totals['coins_sent'] < $p->target_coins || $totals['minutes_live'] < $p->time_minutes
        ) ?? $rungs->last();

        if ($current === null) {
            return [
                'period'       => $period,
                'coins_sent'   => $totals['coins_sent'],
                'minutes_live' => $totals['minutes_live'],
                'target'       => null,
                'coins_pct'    => null,
                'minutes_pct'  => null,
                'overall_pct'  => null,
            ];
        }

        $coinsPct = $this->pct($totals['coins_sent'], $current->target_coins);
        $minutesPct = $this->pct($totals['minutes_live'], $current->time_minutes);

        return [
            'period'       => $period,
            'coins_sent'   => $totals['coins_sent'],
            'minutes_live' => $totals['minutes_live'],
            'target'       => [
                'id' => $current->id, 'target_coins' => $current->target_coins, 'time_minutes' => $current->time_minutes,
            ],
            'coins_pct'    => $coinsPct,
            'minutes_pct'  => $minutesPct,
            'overall_pct'  => (int) round(($coinsPct + $minutesPct) / 2),
        ];
    }

    /** A threshold of 0 is already cleared by definition — never divide by it. */
    protected function pct(int $achieved, int $target): int
    {
        if ($target <= 0) {
            return 100;
        }

        return min(999, (int) floor($achieved * 100 / $target));
    }

    /**
     * Evaluate every approved host for a month in one pass — the admin-triggered
     * equivalent of mehfil's `salary:distribute-host`, minus the cron.
     *
     * @return array{evaluated: int, skipped: int}
     */
    public function evaluateAll(string $period, AdminUser $actor): array
    {
        $this->assertPeriodFormat($period);

        $evaluated = 0;
        $skipped = 0;

        Host::query()->active()->chunkById(100, function ($hosts) use ($period, $actor, &$evaluated, &$skipped) {
            foreach ($hosts as $host) {
                $already = HostGiftTargetResult::where('host_id', $host->id)
                    ->where('period', $period)->whereNotNull('evaluated_at')->exists();

                if ($already) {
                    $skipped++;

                    continue;
                }

                $this->evaluate($host, $period, $actor);
                $evaluated++;
            }
        });

        return ['evaluated' => $evaluated, 'skipped' => $skipped];
    }

    protected function assertPeriodFormat(string $period): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new AgencyException('VALIDATION_ERROR', 'period must be in YYYY-MM format.', 422);
        }
    }

    /** @return array{0: Carbon, 1: Carbon} */
    protected function periodBounds(string $period): array
    {
        $start = Carbon::createFromFormat('Y-m-d', "{$period}-01")->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }
}
