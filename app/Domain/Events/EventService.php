<?php

namespace App\Domain\Events;

use App\Domain\Audit\AuditLogger;
use App\Domain\Wallet\WalletService;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventReward;
use App\Models\EventRewardClaim;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * GFT-093 / GFT-094 — scoring, ranking and reward distribution (A.9b).
 *
 * A.9b in full: "Given rewards for ranks 1–3 and 4–10, when the event ends with 50
 * participants, then exactly 10 users are eligible and each receives the reward for their
 * band, once."
 *
 * Three separate claims, each enforced somewhere specific:
 *   - *exactly 10* — only ranks covered by a band get a claim row.
 *   - *for their band* — the band is matched on the final rank.
 *   - *once* — a unique index on (event_id, user_id), so a concurrent second run inserts
 *     nothing rather than paying twice.
 */
class EventService
{
    public function __construct(
        protected WalletService $wallets,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * Rank participants by score, highest first.
     *
     * Ties share nothing clever: the earlier entrant takes the better rank, because
     * "whoever got there first" is a rule people accept and a random tiebreak is not.
     */
    public function rank(Event $event): int
    {
        $participants = $event->participants()
            ->where('status', '!=', 'disqualified')
            ->orderByDesc('score')
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();

        DB::transaction(function () use ($participants) {
            foreach ($participants as $index => $participant) {
                $participant->forceFill(['rank' => $index + 1])->save();
            }
        });

        return $participants->count();
    }

    /**
     * Award the configured rewards. Idempotent — running it twice pays once.
     *
     * @return array{eligible: int, granted: int, skipped: int}
     *
     * @throws EventException
     */
    public function distributeRewards(Event $event, AdminUser $actor): array
    {
        if (! $event->hasEnded()) {
            throw new EventException(
                'BAD_REQUEST',
                'Rewards can only be handed out after the event has ended.',
                400,
                ['phase' => $event->phase()],
            );
        }

        $rewards = $event->rewards()->get();

        if ($rewards->isEmpty()) {
            throw new EventException('BAD_REQUEST', 'This event has no rewards configured.', 400);
        }

        $this->rank($event);

        $participants = $event->participants()
            ->where('status', '!=', 'disqualified')
            ->whereNotNull('rank')
            ->orderBy('rank')
            ->get();

        $eligible = 0;
        $granted = 0;
        $skipped = 0;

        foreach ($participants as $participant) {
            $reward = $rewards->first(fn (EventReward $r) => $r->covers($participant->rank));

            // Ranks outside every band get nothing — that is how "exactly 10 of 50" holds.
            if ($reward === null) {
                continue;
            }

            $eligible++;

            if (! $reward->hasCapacity()) {
                $skipped++;

                continue;
            }

            // The unique index is the real guard; this check just avoids the exception in
            // the common case of a deliberate re-run.
            if (EventRewardClaim::query()
                ->where('event_id', $event->id)
                ->where('user_id', $participant->user_id)
                ->exists()) {
                $skipped++;

                continue;
            }

            try {
                DB::transaction(function () use ($event, $participant, $reward, $actor, &$granted) {
                    $claim = EventRewardClaim::create([
                        'event_id'   => $event->id,
                        'user_id'    => $participant->user_id,
                        'reward_id'  => $reward->id,
                        'rank'       => $participant->rank,
                        'status'     => 'pending',
                        'claimed_at' => now(),
                    ]);

                    $transactionId = $this->payReward($participant->user_id, $reward, $event, $actor);

                    $claim->forceFill([
                        'status'         => 'paid',
                        'transaction_id' => $transactionId,
                    ])->save();

                    $reward->increment('claimed_count');

                    $granted++;
                });
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // Another run got there first. That is the index doing its job.
                $skipped++;
            }
        }

        $this->audit->log(
            $actor,
            'event.rewards_distributed',
            'events',
            Event::class,
            $event->id,
            null,
            ['eligible' => $eligible, 'granted' => $granted, 'skipped' => $skipped],
        );

        return ['eligible' => $eligible, 'granted' => $granted, 'skipped' => $skipped];
    }

    /**
     * Hand over the actual reward.
     *
     * Coins and diamonds go through WalletService so they land in the ledger like any
     * other movement. Cosmetic rewards have nowhere to go until the user-inventory tables
     * arrive with the mobile app, so the claim is recorded and the grant is not invented.
     */
    protected function payReward(int $userId, EventReward $reward, Event $event, AdminUser $actor): ?string
    {
        $user = User::find($userId);

        if ($user === null) {
            return null;
        }

        $note = "Event reward: {$event->title_en}";

        return match ($reward->reward_type) {
            'coins' => $this->wallets
                ->adjust($user, 'coin', 'credit', $reward->reward_value, $note, $actor)
                ->uuid,
            'diamonds' => $this->wallets
                ->adjust($user, 'diamond', 'credit', $reward->reward_value, $note, $actor)
                ->uuid,
            // frame / badge / vip_days need user_frames, user_badges and
            // user_vip_subscriptions, which land with D.7. Recorded, not faked.
            default => null,
        };
    }

    /** Adds a participant — used by the seeder now, and by the app once D.7d exists. */
    public function join(Event $event, User $user, int $score = 0): EventParticipant
    {
        return EventParticipant::firstOrCreate(
            ['event_id' => $event->id, 'user_id' => $user->id],
            ['joined_at' => now(), 'score' => $score, 'status' => 'joined'],
        );
    }
}
