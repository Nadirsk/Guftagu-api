<?php

namespace App\Domain\Events;

use App\Domain\Wallet\WalletService;
use App\Models\AdminUser;
use App\Models\LeaderboardSnapshot;
use App\Models\RankingReward;
use App\Models\RankingRewardPayout;
use App\Models\RankingRule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GFT-097 / GFT-098 — the leaderboard engine (A.9c, A.9d).
 *
 * docs/02 §8 puts live boards in Redis ZSETs and snapshots them at period close: "the
 * snapshot is the record; Redis is the working surface." Redis arrives with the realtime
 * layer, so the board is computed from the wallet lifetime counters for now — which is
 * where wealth and charm come from in any case (docs/02 §7: "Wealth points = lifetime
 * coins **spent**. Charm points = lifetime diamonds **earned**."). The snapshot-and-pay
 * half, which is the part that moves money, works the same either way.
 */
class LeaderboardService
{
    public function __construct(protected WalletService $wallets)
    {
    }

    /**
     * The current board for a rule.
     *
     * A.9c — `min_threshold` is applied in the query, so someone below it never appears
     * regardless of how few people are above them. Filtering after ranking would let a
     * quiet week promote an ineligible user onto the board.
     *
     * @return Collection<int, array{rank: int, entity_id: int, score: int, display_name: ?string, guftagu_id: ?string}>
     */
    public function board(RankingRule $rule): Collection
    {
        $column = match ($rule->metric) {
            'coins_spent'     => 'wallets.lifetime_coins_spent',
            'diamonds_earned' => 'wallets.lifetime_diamonds_earned',
            default           => 'wallets.lifetime_coins_spent',
        };

        return DB::table('wallets')
            ->join('users', 'users.id', '=', 'wallets.user_id')
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->whereNull('users.deleted_at')
            ->where('users.status', User::STATUS_ACTIVE)
            ->where($column, '>=', max(1, $rule->min_threshold))
            ->orderByDesc($column)
            ->orderBy('users.id')          // a stable tiebreak, so the board does not shuffle
            ->limit($rule->top_n)
            ->get([
                'users.id AS entity_id',
                'users.guftagu_id',
                'user_profiles.display_name',
                DB::raw("{$column} AS score"),
            ])
            ->values()
            ->map(fn ($row, $index) => [
                'rank'         => $index + 1,
                'entity_id'    => (int) $row->entity_id,
                'score'        => (int) $row->score,
                'guftagu_id'   => $row->guftagu_id,
                'display_name' => $row->display_name,
            ]);
    }

    /**
     * Freeze the current board as the record for a period.
     *
     * Re-snapshotting the same period replaces it rather than duplicating — the unique
     * index on (rule_key, period_start, rank) would refuse a second set anyway, and
     * silently failing would be worse than being explicit.
     *
     * @return int rows written
     */
    public function snapshot(RankingRule $rule, ?Carbon $periodStart = null, ?Carbon $periodEnd = null): int
    {
        [$start, $end] = $this->periodFor($rule, $periodStart, $periodEnd);

        $board = $this->board($rule);

        return DB::transaction(function () use ($rule, $board, $start, $end) {
            LeaderboardSnapshot::query()
                ->where('rule_key', $rule->key)
                ->where('period_start', $start->toDateString())
                ->delete();

            foreach ($board as $entry) {
                LeaderboardSnapshot::create([
                    'rule_key'     => $rule->key,
                    'period_start' => $start->toDateString(),
                    'period_end'   => $end->toDateString(),
                    'rank'         => $entry['rank'],
                    'entity_type'  => 'user',
                    'entity_id'    => $entry['entity_id'],
                    'score'        => $entry['score'],
                ]);
            }

            return $board->count();
        });
    }

    /**
     * Pay the rewards for a snapshotted period.
     *
     * A.9d — "re-running the payout job pays nothing further". A payout row is created
     * first, under a unique index on (snapshot_id, user_id); only then is money moved. A
     * second run finds the rows already there and does nothing.
     *
     * @return array{paid: int, skipped: int, total_value: int}
     */
    public function payRewards(RankingRule $rule, Carbon $periodStart, AdminUser $actor): array
    {
        $snapshots = LeaderboardSnapshot::query()
            ->where('rule_key', $rule->key)
            ->where('period_start', $periodStart->toDateString())
            ->orderBy('rank')
            ->get();

        if ($snapshots->isEmpty()) {
            throw new EventException(
                'NOT_FOUND',
                'There is no snapshot for that period. Take one before paying rewards.',
                404,
            );
        }

        $rewards = RankingReward::query()
            ->where('rule_key', $rule->key)
            ->where('is_active', true)
            ->orderBy('rank_from')
            ->get();

        $paid = 0;
        $skipped = 0;
        $totalValue = 0;

        foreach ($snapshots as $snapshot) {
            $reward = $rewards->first(fn (RankingReward $r) => $r->covers($snapshot->rank));

            if ($reward === null) {
                continue;
            }

            if (RankingRewardPayout::query()
                ->where('snapshot_id', $snapshot->id)
                ->where('user_id', $snapshot->entity_id)
                ->exists()) {
                $skipped++;

                continue;
            }

            try {
                DB::transaction(function () use ($snapshot, $reward, $rule, $actor, &$paid, &$totalValue) {
                    // The claim row goes in BEFORE the money moves, so a crash between the
                    // two leaves a `pending` record rather than an untracked payment.
                    $payout = RankingRewardPayout::create([
                        'snapshot_id'  => $snapshot->id,
                        'user_id'      => $snapshot->entity_id,
                        'reward_type'  => $reward->reward_type,
                        'reward_value' => $reward->reward_value,
                        'status'       => RankingRewardPayout::PENDING,
                    ]);

                    $user = User::find($snapshot->entity_id);

                    if ($user === null) {
                        $payout->forceFill([
                            'status' => RankingRewardPayout::FAILED,
                            'error'  => 'The user no longer exists.',
                        ])->save();

                        return;
                    }

                    $transactionId = match ($reward->reward_type) {
                        'coins' => $this->wallets->adjust(
                            $user, 'coin', 'credit', $reward->reward_value,
                            "Ranking reward: {$rule->key} rank {$snapshot->rank}", $actor,
                        )->uuid,
                        'diamonds' => $this->wallets->adjust(
                            $user, 'diamond', 'credit', $reward->reward_value,
                            "Ranking reward: {$rule->key} rank {$snapshot->rank}", $actor,
                        )->uuid,
                        default => null,
                    };

                    $payout->forceFill([
                        'status'         => RankingRewardPayout::PAID,
                        'paid_at'        => now(),
                        'transaction_id' => $transactionId,
                    ])->save();

                    $paid++;
                    $totalValue += $reward->reward_value;
                });
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                $skipped++;
            }
        }

        return ['paid' => $paid, 'skipped' => $skipped, 'total_value' => $totalValue];
    }

    /**
     * The period a rule is currently in.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodFor(RankingRule $rule, ?Carbon $start = null, ?Carbon $end = null): array
    {
        if ($start !== null && $end !== null) {
            return [$start, $end];
        }

        $now = $start ?? now();

        return match ($rule->period) {
            'weekly'   => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'monthly'  => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            // all_time still needs a row key; the epoch start keeps it stable.
            'all_time' => [Carbon::createFromTimestamp(0), $now->copy()],
            default    => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }
}
