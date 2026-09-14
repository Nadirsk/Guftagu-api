<?php

namespace App\Domain\Events;

use App\Domain\Store\InventoryService;
use App\Domain\Wallet\WalletService;
use App\Models\AdminUser;
use App\Models\Badge;
use App\Models\Event;
use App\Models\EventClaim;
use App\Models\EventProgress;
use App\Models\EventTier;
use App\Models\EventTierReward;
use App\Models\LeaderboardSnapshot;
use App\Models\RankingRule;
use App\Models\RewardCatalogItem;
use App\Models\StoreItem;
use App\Models\User;
use App\Models\VipTier;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The engine behind `recharge_activity` and `weekly_star` — the two campaign event
 * types built on `event_tiers`/`event_tier_rewards`/`event_progress`/`event_claims`
 * rather than the rank-band shape `event`/`tournament`/`lucky_draw` already use.
 *
 * `weekly_star` deliberately reuses {@see LeaderboardService} for standings instead of
 * re-deriving a windowed leaderboard: `RankingRule` already computes "this period's"
 * ranking (docs/02 §8), and duplicating that here would be two engines that could drift
 * apart. This class only adds what that engine does not have — reward *bundles* instead
 * of one coins/diamonds value per band, and the recharge-threshold half entirely.
 */
class EventCampaignService
{
    public function __construct(
        protected WalletService $wallets,
        protected InventoryService $inventory,
        protected LeaderboardService $leaderboards,
    ) {
    }

    /**
     * The sentinel `period_type`/label used when an event/tier has no recurring cadence
     * at all — a fixed-date custom event ("Sep 9 to Nov 9") is scored against its own
     * `starts_at`/`ends_at` rather than a rolling daily/weekly/monthly window.
     */
    public const FIXED = 'fixed';

    /**
     * The window a cadence is currently in. `daily`/`weekly`/`monthly` are a rolling
     * window from now — same rule as {@see LeaderboardService::periodFor()}, kept
     * separate because a campaign event's cadence lives on the tier (or the event as a
     * fallback), not on a `RankingRule`. Anything else (no cadence configured, i.e.
     * {@see FIXED}) is the event's own fixed window — what a one-off custom event with
     * concrete start/end dates actually means by "the period".
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodFor(Event $event, ?string $periodType, ?Carbon $at = null): array
    {
        $now = ($at ?? now())->copy();

        return match ($periodType) {
            'daily'   => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'weekly'  => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'monthly' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            default   => [$event->starts_at->copy(), $event->ends_at->copy()],
        };
    }

    /**
     * A user's live score for one cadence of a threshold event — e.g. "My Monthly
     * Recharge", or "how much of this Rose Gift have I sent between Sep 9 and Nov 9".
     * Recomputed from the ledger on every read (never trusted from a prior write) and
     * upserted into `event_progress` so there is a record of what a claim was judged
     * against.
     *
     * @return array{score: int, period_start: Carbon, period_end: Carbon}
     */
    public function myProgress(Event $event, User $user, string $periodType): array
    {
        [$start, $end] = $this->periodFor($event, $periodType);
        $score = $this->scoreFor($event, $user->id, $start, $end);

        EventProgress::updateOrCreate(
            [
                'event_id'    => $event->id,
                'user_id'     => $user->id,
                'period_type' => $periodType,
                'period_start' => $start->toDateString(),
            ],
            ['period_end' => $end->toDateString(), 'score' => $score],
        );

        return ['score' => $score, 'period_start' => $start, 'period_end' => $end];
    }

    /**
     * The live board for a rank_range event. `weekly_star` (and anything else pointing
     * at a `ranking_rule_key`) delegates entirely to that `RankingRule` — reusing the
     * existing engine rather than re-deriving it. Everything else (a `custom` event
     * scored by `progress_metric`) is ranked from the real transaction-level ledgers,
     * windowed to the event's own cadence — see `windowedBoard()`.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function board(Event $event): Collection
    {
        if ($event->ranking_rule_key !== null) {
            $rule = RankingRule::query()->where('key', $event->ranking_rule_key)->firstOrFail();

            return $this->leaderboards->board($rule);
        }

        return $this->windowedBoard($event);
    }

    /**
     * Rank every user by `progress_metric` within the event's current window — the
     * metric-driven counterpart to `RankingRule` + `LeaderboardService::board()`, built
     * on real per-transaction data (`coin_transactions`, `diamond_transactions`,
     * `gift_transactions`) rather than a lifetime wallet counter, because a fixed-date
     * custom event ("only what happened between these two dates") cannot be answered
     * from a total that never resets.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function windowedBoard(Event $event, int $limit = 100): Collection
    {
        [$start, $end] = $this->periodFor($event, $event->period);

        $query = $this->metricQuery($event);

        if ($query === null) {
            return collect();
        }

        $rows = $query->whereBetween('created_at', [$start, $end])
            ->groupBy('user_id')->orderByDesc('score')->limit($limit)->get();
        $profiles = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->whereIn('users.id', $rows->pluck('user_id'))
            ->get(['users.id', 'users.guftagu_id', 'user_profiles.display_name', 'user_profiles.avatar_url'])
            ->keyBy('id');

        return $rows->values()->map(function ($row, $index) use ($profiles) {
            $profile = $profiles->get($row->user_id);

            return [
                'rank'         => $index + 1,
                'entity_id'    => (int) $row->user_id,
                'score'        => (int) $row->score,
                'guftagu_id'   => $profile->guftagu_id ?? null,
                'display_name' => $profile->display_name ?? null,
                'avatar_url'   => $profile->avatar_url ?? null,
            ];
        });
    }

    /**
     * Claim a threshold tier's bundle — the app's "Receive" button.
     *
     * @return array{ok: bool, error?: string, claim?: EventClaim}
     */
    public function claimTier(Event $event, EventTier $tier, User $user): array
    {
        if ($tier->event_id !== $event->id || $tier->tier_type !== EventTier::THRESHOLD) {
            return ['ok' => false, 'error' => 'not_a_threshold_tier'];
        }

        if (! $event->isLive()) {
            return ['ok' => false, 'error' => 'event_not_live'];
        }

        $periodType = $tier->period ?? $event->period ?? self::FIXED;
        $progress = $this->myProgress($event, $user, $periodType);

        if (! $tier->isClearedBy($progress['score'])) {
            return ['ok' => false, 'error' => 'threshold_not_met'];
        }

        try {
            $claim = DB::transaction(function () use ($event, $tier, $user, $periodType, $progress) {
                $claim = EventClaim::create([
                    'event_id'      => $event->id,
                    'event_tier_id' => $tier->id,
                    'user_id'       => $user->id,
                    'period_type'   => $periodType,
                    'period_start'  => $progress['period_start']->toDateString(),
                    'status'        => EventClaim::PENDING,
                ]);

                $granted = $this->grantBundle($tier, $user, $event, $claim->period_start->toDateString());

                $claim->forceFill([
                    'status'     => EventClaim::PAID,
                    'claimed_at' => now(),
                    'granted'    => $granted,
                ])->save();

                return $claim;
            });
        } catch (UniqueConstraintViolationException) {
            return ['ok' => false, 'error' => 'already_claimed'];
        }

        return ['ok' => true, 'claim' => $claim];
    }

    /**
     * Distribute a rank_range tier's bundles for a closed period — the admin-side
     * counterpart to `claimTier`, since a rank is not final until the period ends and so
     * cannot be user-claimed mid-period.
     *
     * Two sources of standings, same distribution logic either way:
     *  - `ranking_rule_key` set (e.g. `weekly_star`) — requires a `LeaderboardSnapshot`
     *    already taken (`POST ranking-rules/{rule}/snapshot`), same precondition
     *    `LeaderboardService::payRewards()` has.
     *  - otherwise — a `custom` event scored by `progress_metric`. A fixed-window event
     *    (no `period`) must have actually ended first; a recurring one is ranked live,
     *    same as the snapshot path conceptually, just without a separate freeze step
     *    (nothing else reads from it, so there is nothing a snapshot would protect here).
     *
     * @return array{eligible: int, granted: int, skipped: int}
     */
    public function distributeTiers(Event $event, AdminUser $actor, ?Carbon $periodStart = null): array
    {
        $tiers = $event->tiers()->where('tier_type', EventTier::RANK_RANGE)->get();

        if ($tiers->isEmpty()) {
            throw new EventException('BAD_REQUEST', 'This event has no rank-band tiers to distribute.', 400);
        }

        if ($event->ranking_rule_key !== null) {
            [$standings, $start, $periodType] = $this->rankingRuleStandings($event, $periodStart);
        } else {
            if ($event->period === null && ! $event->hasEnded()) {
                throw new EventException('BAD_REQUEST', 'This event has not ended yet.', 400, ['phase' => $event->phase()]);
            }

            [$start, $end] = $this->periodFor($event, $event->period, $periodStart);
            $standings = $this->windowedBoard($event);
            $periodType = $event->period ?? self::FIXED;
        }

        $eligible = 0;
        $granted = 0;
        $skipped = 0;

        foreach ($standings as $row) {
            $tier = $tiers->first(fn (EventTier $t) => $t->coversRank($row['rank']));

            if ($tier === null) {
                continue;
            }

            $eligible++;
            $user = User::find($row['entity_id']);

            if ($user === null) {
                $skipped++;

                continue;
            }

            try {
                DB::transaction(function () use ($event, $tier, $user, $periodType, $start, &$granted) {
                    $claim = EventClaim::create([
                        'event_id'      => $event->id,
                        'event_tier_id' => $tier->id,
                        'user_id'       => $user->id,
                        'period_type'   => $periodType,
                        'period_start'  => $start->toDateString(),
                        'status'        => EventClaim::PENDING,
                    ]);

                    $bundle = $this->grantBundle($tier, $user, $event, $start->toDateString());

                    $claim->forceFill([
                        'status'     => EventClaim::PAID,
                        'claimed_at' => now(),
                        'granted'    => $bundle,
                    ])->save();

                    $granted++;
                });
            } catch (UniqueConstraintViolationException) {
                $skipped++;
            }
        }

        return ['eligible' => $eligible, 'granted' => $granted, 'skipped' => $skipped];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: Carbon, 2: string}
     */
    protected function rankingRuleStandings(Event $event, ?Carbon $periodStart): array
    {
        $rule = RankingRule::query()->where('key', $event->ranking_rule_key)->firstOrFail();
        $start = $periodStart ?? $this->leaderboards->periodFor($rule)[0];

        $snapshots = LeaderboardSnapshot::query()
            ->where('rule_key', $rule->key)
            ->where('period_start', $start->toDateString())
            ->orderBy('rank')
            ->get();

        if ($snapshots->isEmpty()) {
            throw new EventException(
                'NOT_FOUND',
                'No leaderboard snapshot for that period yet — take one before distributing.',
                404,
            );
        }

        $standings = $snapshots->map(fn ($row) => ['rank' => $row->rank, 'entity_id' => $row->entity_id])->all();

        return [$standings, $start, $rule->period];
    }

    /**
     * One user's score for `progress_metric` within a window — the transaction-level
     * query every threshold claim and windowed board is judged against.
     */
    protected function scoreFor(Event $event, int $userId, Carbon $start, Carbon $end): int
    {
        return match ($event->progress_metric) {
            'recharge_amount' => (int) DB::table('coin_transactions')
                ->where('user_id', $userId)->where('type', 'recharge')->where('direction', 'credit')
                ->whereBetween('created_at', [$start, $end])->sum('amount'),
            'coins_spent' => (int) DB::table('coin_transactions')
                ->where('user_id', $userId)->where('direction', 'debit')
                ->whereBetween('created_at', [$start, $end])->sum('amount'),
            'diamonds_earned' => (int) DB::table('diamond_transactions')
                ->where('user_id', $userId)->where('direction', 'credit')
                ->whereBetween('created_at', [$start, $end])->sum('amount'),
            'gift_value' => (int) $this->giftTransactionsFor($event, $start, $end)
                ->where('sender_id', $userId)->sum('coin_value'),
            'gift_count' => (int) $this->giftTransactionsFor($event, $start, $end)
                ->where('sender_id', $userId)->sum('quantity'),
            default => 0,
        };
    }

    /**
     * The grouped, all-users version of `scoreFor()` — one row per sender, aliased to
     * `user_id` regardless of source table, ready for `groupBy('user_id')`. Aliases
     * cannot be referenced in a `WHERE` (only `GROUP BY`/`ORDER BY`), which is exactly
     * why `scoreFor()` is a separate query rather than this one plus a user filter.
     */
    protected function metricQuery(Event $event): ?Builder
    {
        return match ($event->progress_metric) {
            'recharge_amount' => DB::table('coin_transactions')
                ->select('user_id', DB::raw('SUM(amount) as score'))
                ->where('type', 'recharge')->where('direction', 'credit'),
            'coins_spent' => DB::table('coin_transactions')
                ->select('user_id', DB::raw('SUM(amount) as score'))
                ->where('direction', 'debit'),
            'diamonds_earned' => DB::table('diamond_transactions')
                ->select('user_id', DB::raw('SUM(amount) as score'))
                ->where('direction', 'credit'),
            'gift_value' => $this->giftTransactionsFor($event, null, null)
                ->select('sender_id as user_id', DB::raw('SUM(coin_value) as score')),
            'gift_count' => $this->giftTransactionsFor($event, null, null)
                ->select('sender_id as user_id', DB::raw('SUM(quantity) as score')),
            default => null,
        };
    }

    /** `gift_transactions`, optionally narrowed to one gift or gift category via `metric_ref_id`/`metric_ref_type`. */
    protected function giftTransactionsFor(Event $event, ?Carbon $start, ?Carbon $end): Builder
    {
        $query = DB::table('gift_transactions');

        if ($start !== null && $end !== null) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        if ($event->metric_ref_id !== null) {
            $column = $event->metric_ref_type === 'gift_category' ? 'gift_category_id' : 'gift_id';
            $query->where($column, $event->metric_ref_id);
        }

        return $query;
    }

    /**
     * Grant every reward line of a tier's bundle. A `manual` catalog entry — anything an
     * admin invented with no automated handler — is recorded with `granted: false` and
     * `needs_manual_fulfillment: true`, the same "recorded, not faked" convention
     * `EventService::payReward()` already used for a type with no inventory table.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function grantBundle(EventTier $tier, User $user, Event $event, string $periodStart): array
    {
        $out = [];

        foreach ($tier->rewards as $reward) {
            $catalog = $reward->catalog;

            $entry = [
                'reward_catalog_id'        => $reward->reward_catalog_id,
                'name'                     => $catalog?->name,
                'handler_key'              => $catalog?->handler_key,
                'reward_value'             => $reward->reward_value,
                'duration_days'            => $reward->duration_days,
                'granted'                  => false,
                'needs_manual_fulfillment' => false,
                'transaction_id'           => null,
            ];

            $idempotencyKey = "event_tier_reward:{$reward->id}:{$user->id}:{$periodStart}";

            try {
                switch ($catalog?->handler_key) {
                    case 'coins':
                        $entry['transaction_id'] = $this->wallets->creditSystem(
                            $user, 'coin', $reward->reward_value, 'event_tier_reward', $idempotencyKey,
                        )->uuid;
                        $entry['granted'] = true;
                        break;

                    case 'diamonds':
                        $entry['transaction_id'] = $this->wallets->creditSystem(
                            $user, 'diamond', $reward->reward_value, 'event_tier_reward', $idempotencyKey,
                        )->uuid;
                        $entry['granted'] = true;
                        break;

                    case 'vip':
                        $vipTier = VipTier::find($catalog->handler_ref_id);
                        if ($vipTier !== null) {
                            $this->inventory->grantVip($user, $vipTier, $reward->duration_days ?? 1, 'event');
                            $entry['granted'] = true;
                        }
                        break;

                    case 'frame':
                    case 'chat_bubble':
                    case 'entry_effect':
                        $item = StoreItem::find($catalog->handler_ref_id);
                        if ($item !== null) {
                            $this->inventory->grantStoreItem($user, $item, $reward->duration_days, 'event');
                            $entry['granted'] = true;
                        }
                        break;

                    case 'badge':
                        $badge = Badge::find($catalog->handler_ref_id);
                        if ($badge !== null) {
                            $this->inventory->grantBadge($user, $badge, $reward->duration_days, 'event');
                            $entry['granted'] = true;
                        }
                        break;

                    case 'manual':
                        $entry['needs_manual_fulfillment'] = true;
                        break;

                    default:
                        break;
                }
            } catch (\Throwable) {
                // Left ungranted — the claim row still records the attempt via `granted`.
            }

            $out[] = $entry;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function tierPayload(EventTier $tier): array
    {
        return [
            'id'              => $tier->id,
            'tier_type'       => $tier->tier_type,
            'period'          => $tier->period,
            'threshold_value' => $tier->threshold_value,
            'rank_from'       => $tier->rank_from,
            'rank_to'         => $tier->rank_to,
            'label'           => $tier->label,
            'image_url'       => $tier->image_url,
            'sort_order'      => $tier->sort_order,
            'rewards'         => $tier->rewards->map(fn (EventTierReward $r) => $this->rewardPayload($r))->values(),
        ];
    }

    /** @return array<string, mixed> */
    public function rewardPayload(EventTierReward $reward): array
    {
        return [
            'id'            => $reward->id,
            'reward_value'  => $reward->reward_value,
            'duration_days' => $reward->duration_days,
            'label'         => $reward->label,
            'catalog'       => $this->catalogPayload($reward->catalog),
            'grantable'     => $reward->isGrantable(),
        ];
    }

    /** @return array<string, mixed> */
    public function catalogPayload(RewardCatalogItem $catalog): array
    {
        return [
            'id'               => $catalog->id,
            'name'             => $catalog->name,
            'icon_url'         => $catalog->icon_url,
            'description'      => $catalog->description,
            'handler_key'      => $catalog->handler_key,
            'handler_ref_id'   => $catalog->handler_ref_id,
            'handler_ref_label' => $catalog->refLabel(),
            'is_active'        => $catalog->is_active,
        ];
    }
}
