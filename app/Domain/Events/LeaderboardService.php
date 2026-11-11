<?php

namespace App\Domain\Events;

use App\Domain\Store\InventoryService;
use App\Domain\Wallet\WalletService;
use App\Models\AdminUser;
use App\Models\LeaderboardSnapshot;
use App\Models\RankingReward;
use App\Models\RankingRewardPayout;
use App\Models\RankingRule;
use App\Models\User;
use App\Models\UserProfile;
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
    public function __construct(protected WalletService $wallets, protected InventoryService $inventory)
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
    public function board(RankingRule $rule, ?Carbon $at = null): Collection
    {
        // A board nothing can be computed for (CP) has no standings rather than a wrong one.
        if (! $rule->isComputable()) {
            return collect();
        }

        return $this->scores($rule, $at)
            ->join('users', 'users.id', '=', 'g.user_id')
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->whereNull('users.deleted_at')
            ->where('users.status', User::STATUS_ACTIVE)
            ->where('g.score', '>=', max(1, $rule->min_threshold))
            ->orderByDesc('g.score')
            ->orderBy('users.id')          // a stable tiebreak, so the board does not shuffle
            ->limit($rule->top_n)
            ->get([
                'users.id AS entity_id',
                'users.guftagu_id',
                'user_profiles.display_name',
                'user_profiles.avatar_url',
                'user_profiles.gender',
                'g.score',
            ])
            ->values()
            ->map(fn ($row, $index) => [
                'rank'         => $index + 1,
                'entity_id'    => (int) $row->entity_id,
                'score'        => (int) $row->score,
                'guftagu_id'   => $row->guftagu_id,
                'display_name' => $row->display_name,
                'avatar_url'   => $row->avatar_url ?: UserProfile::defaultAvatarUrl($row->gender, $row->entity_id),
            ]);
    }

    /**
     * Per-user totals for the rule's current period, as a derived table `g(user_id, score)`.
     *
     * Rich (`coins_spent`) = coin value of room gifts the user SENT; Charm
     * (`diamonds_earned`) = coin value of room gifts the user RECEIVED. Only room gifts
     * count (`room_id` set); the window is the rule's current day / week / month, and
     * `all_time` has none.
     */
    protected function scores(RankingRule $rule, ?Carbon $at = null): \Illuminate\Database\Query\Builder
    {
        $side = $rule->metric === 'diamonds_earned' ? 'receiver_id' : 'sender_id';

        $inner = DB::table('gift_transactions')
            ->whereNotNull('room_id')
            ->selectRaw("{$side} AS user_id, SUM(coin_value) AS score")
            ->groupBy($side);

        if ($rule->period !== 'all_time') {
            // `$at` picks the period that contains it — how a finished week is rebuilt.
            [$start, $end] = $this->periodFor($rule, $at);
            $inner->whereBetween('created_at', [$start, $end]);
        }

        return DB::query()->fromSub($inner, 'g');
    }

    /** @return array{rank: ?int, score: int} the user's place on the rule's current board. */
    public function standing(RankingRule $rule, int $userId): array
    {
        if (! $rule->isComputable()) {
            return ['rank' => null, 'score' => 0];
        }

        $score = (int) ($this->scores($rule)->where('g.user_id', $userId)->value('g.score') ?? 0);

        if ($score < max(1, $rule->min_threshold)) {
            return ['rank' => null, 'score' => $score];
        }

        $ahead = $this->scores($rule)
            ->join('users', 'users.id', '=', 'g.user_id')
            ->whereNull('users.deleted_at')
            ->where('users.status', User::STATUS_ACTIVE)
            ->where('g.score', '>', $score)
            ->count();

        return ['rank' => 1 + $ahead, 'score' => $score];
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

        // The board of the period being frozen — not necessarily the current one.
        $board = $this->board($rule, $periodStart ?? $start);

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
    public function payRewards(RankingRule $rule, Carbon $periodStart, ?AdminUser $actor = null): array
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
            ->with('storeItem')
            ->where('rule_key', $rule->key)
            ->where('is_active', true)
            ->orderBy('rank_from')
            ->orderBy('id')
            ->get();

        $paid = 0;
        $skipped = 0;
        $totalValue = 0;

        foreach ($snapshots as $snapshot) {
            // A rank can earn several rows (coins + a frame + …); each is its own payout.
            foreach ($rewards->filter(fn (RankingReward $r) => $r->covers($snapshot->rank)) as $reward) {
                if (RankingRewardPayout::query()
                    ->where('snapshot_id', $snapshot->id)
                    ->where('user_id', $snapshot->entity_id)
                    ->where('ranking_reward_id', $reward->id)
                    ->exists()) {
                    $skipped++;

                    continue;
                }

                try {
                    DB::transaction(function () use ($snapshot, $reward, $rule, $actor, &$paid, &$totalValue) {
                        // The claim row goes in BEFORE the money moves, so a crash between the
                        // two leaves a `pending` record rather than an untracked payment.
                        $payout = RankingRewardPayout::create([
                            'snapshot_id'       => $snapshot->id,
                            'user_id'           => $snapshot->entity_id,
                            'ranking_reward_id' => $reward->id,
                            'reward_type'       => $reward->reward_type,
                            'reward_value'      => $reward->reward_value,
                            'store_item_id'     => $reward->store_item_id,
                            'duration_days'     => $reward->duration_days,
                            'status'            => RankingRewardPayout::PENDING,
                        ]);

                        $user = User::find($snapshot->entity_id);

                        if ($user === null || ! $reward->isPayable()) {
                            $payout->forceFill([
                                'status' => RankingRewardPayout::FAILED,
                                'error'  => $user === null
                                    ? 'The user no longer exists.'
                                    : 'The reward item is missing or inactive.',
                            ])->save();

                            return;
                        }

                        $memo = "Ranking reward: {$rule->key} rank {$snapshot->rank}";

                        // An admin's manual pay is audited as theirs; the scheduled job has no
                        // admin, so it credits as the system, keyed to the payout row.
                        $credit = fn (string $currency) => $actor !== null
                            ? $this->wallets->adjust($user, $currency, 'credit', $reward->reward_value, $memo, $actor)
                            : $this->wallets->creditSystem($user, $currency, $reward->reward_value, 'ranking_reward', "ranking_reward:{$payout->id}");

                        $transactionId = match ($reward->reward_type) {
                            'coins'    => $credit('coin')->uuid,
                            'diamonds' => $credit('diamond')->uuid,
                            default    => null,
                        };

                        if (RankingReward::isItemType($reward->reward_type)) {
                            $this->inventory->grantStoreItem($user, $reward->storeItem, $reward->duration_days, 'ranking');
                        }

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
