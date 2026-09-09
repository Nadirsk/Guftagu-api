<?php

namespace App\Domain\Engagement;

use App\Domain\Wallet\WalletService;
use App\Models\CheckinReward;
use App\Models\DailyCheckin;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Epic D.7c (docs/02 §7, docs/04 D.7c) — the 7-day check-in streak.
 *
 * The streak is derived from `daily_checkins` history rather than stored as a counter —
 * same reasoning as `User::effectiveStatus()`: a counter can drift from the rows that are
 * supposed to justify it, a derivation cannot. The whole rule is {@see resolveToday()}.
 *
 * A completed day 7 rolls straight into a new cycle at day 1 rather than freezing — this
 * repeats indefinitely for as long as the streak stays unbroken.
 */
class CheckinService
{
    public function __construct(protected WalletService $wallet)
    {
    }

    /**
     * @return array{current_streak_day: int, claimed_today: bool, week: array<int, array<string, mixed>>}
     */
    public function status(User $user): array
    {
        $resolved = $this->resolveToday($user);
        $catalogue = CheckinReward::query()->where('is_active', true)->get()->keyBy('streak_day');

        $week = [];

        for ($day = 1; $day <= 7; $day++) {
            $slot = $catalogue->get($day);

            $status = match (true) {
                $slot === null => 'unavailable',
                $day === $resolved['streak_day'] && $resolved['claimed_today'] => 'claimed',
                $day === $resolved['streak_day'] => 'today',
                $day < $resolved['streak_day'] => 'claimed',
                default => 'locked',
            };

            $week[] = [
                'day'          => $day,
                'reward_type'  => $slot?->reward_type,
                'reward_value' => $slot?->reward_value,
                'icon_url'     => $slot?->icon_url,
                'payable'      => $slot !== null && in_array($slot->reward_type, CheckinReward::TYPES, true),
                'status'       => $status,
            ];
        }

        return [
            'current_streak_day' => $resolved['streak_day'],
            'claimed_today'      => $resolved['claimed_today'],
            'week'               => $week,
        ];
    }

    /**
     * @return array{ok: bool, error?: string, checkin?: DailyCheckin}
     */
    public function claim(User $user): array
    {
        $resolved = $this->resolveToday($user);

        if ($resolved['claimed_today']) {
            return ['ok' => false, 'error' => 'already_claimed'];
        }

        $slot = CheckinReward::query()
            ->where('streak_day', $resolved['streak_day'])
            ->where('is_active', true)
            ->first();

        if ($slot === null) {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        $today = now()->toDateString();

        try {
            $checkin = DB::transaction(function () use ($user, $today, $resolved, $slot) {
                $checkin = DailyCheckin::create([
                    'user_id'      => $user->id,
                    'checkin_date' => $today,
                    'streak_day'   => $resolved['streak_day'],
                    'reward_type'  => $slot->reward_type,
                    'reward_value' => $slot->reward_value,
                ]);

                if (in_array($slot->reward_type, CheckinReward::TYPES, true)) {
                    $currency = $slot->reward_type === 'diamonds' ? Wallet::DIAMOND : Wallet::COIN;

                    $this->wallet->creditSystem(
                        $user,
                        $currency,
                        $slot->reward_value,
                        'daily_checkin',
                        idempotencyKey: "daily_checkin:{$user->id}:{$today}",
                    );
                }

                return $checkin;
            });
        } catch (QueryException) {
            // The unique(user_id, checkin_date) constraint caught a concurrent claim that
            // resolveToday()'s read did not see coming — same outcome either way.
            return ['ok' => false, 'error' => 'already_claimed'];
        }

        return ['ok' => true, 'checkin' => $checkin];
    }

    /**
     * The whole streak rule, in one place:
     *
     *  - No prior check-in at all → day 1, not yet claimed.
     *  - Last check-in was today → that day again, already claimed.
     *  - Last check-in was yesterday → the next day (wrapping 7 back to 1), not yet claimed.
     *  - Anything older than yesterday → the streak broke; day 1, not yet claimed.
     *
     * @return array{streak_day: int, claimed_today: bool}
     */
    protected function resolveToday(User $user): array
    {
        $last = DailyCheckin::query()
            ->where('user_id', $user->id)
            ->orderByDesc('checkin_date')
            ->first();

        if ($last === null) {
            return ['streak_day' => 1, 'claimed_today' => false];
        }

        if ($last->checkin_date->isToday()) {
            return ['streak_day' => $last->streak_day, 'claimed_today' => true];
        }

        if ($last->checkin_date->isYesterday()) {
            return ['streak_day' => $last->streak_day >= 7 ? 1 : $last->streak_day + 1, 'claimed_today' => false];
        }

        return ['streak_day' => 1, 'claimed_today' => false];
    }
}
