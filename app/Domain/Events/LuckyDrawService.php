<?php

namespace App\Domain\Events;

use App\Domain\Audit\AuditLogger;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\LuckyDraw;
use Illuminate\Support\Facades\DB;

/**
 * GFT-095 — provable-fairness lucky draws (A.9a).
 *
 * docs/02 §9: "`seed_hash` is published **before** the draw and the seed revealed after —
 * provable fairness, which matters when real money bought the entry."
 *
 * The commitment scheme, and why each half is needed:
 *
 *  - A random seed is generated when the draw is created. Only its SHA-256 is stored where
 *    anyone can see it. Publishing the hash first is a promise: the operator has already
 *    chosen, and cannot choose again once entries are in.
 *  - After the draw the raw seed is published. Anyone can hash it, check it matches the
 *    commitment made beforehand, and recompute the winners themselves.
 *
 * Selection therefore has to be **deterministic given the seed**. It does not use
 * `rand()`, `shuffle()` or anything else whose internals vary between PHP builds — a
 * result nobody outside can reproduce is not provable fairness, it is just a claim.
 */
class LuckyDrawService
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    /** Creates the draw and commits to a seed without revealing it. */
    public function create(Event $event, array $attributes): LuckyDraw
    {
        $seed = bin2hex(random_bytes(32));

        return LuckyDraw::create([
            'event_id'     => $event->id,
            'draw_at'      => $attributes['draw_at'],
            'prize_pool'   => $attributes['prize_pool'] ?? null,
            'winner_count' => $attributes['winner_count'] ?? 1,
            'algorithm'    => $attributes['algorithm'] ?? 'random',
            'seed_hash'    => hash('sha256', $seed),
            // Held back until the draw runs. The hash above is the public commitment.
            'seed'         => $seed,
        ]);
    }

    /**
     * Run the draw.
     *
     * @throws EventException
     */
    public function draw(LuckyDraw $luckyDraw, AdminUser $actor): LuckyDraw
    {
        if ($luckyDraw->drawn_at !== null) {
            throw new EventException('BAD_REQUEST', 'That draw has already been run.', 400);
        }

        if ($luckyDraw->draw_at->isFuture()) {
            throw new EventException(
                'BAD_REQUEST',
                'This draw is not due yet. Running it early would break the commitment.',
                400,
                ['draw_at' => $luckyDraw->draw_at->toIso8601ZuluString()],
            );
        }

        $entrants = $luckyDraw->event
            ->participants()
            ->where('status', '!=', 'disqualified')
            // Ordered so the input to the selection is itself deterministic.
            ->orderBy('user_id')
            ->get(['user_id']);

        if ($entrants->isEmpty()) {
            throw new EventException('BAD_REQUEST', 'Nobody entered this draw.', 400);
        }

        $winners = self::selectWinners(
            $luckyDraw->seed,
            $entrants->pluck('user_id')->all(),
            min($luckyDraw->winner_count, $entrants->count()),
            $luckyDraw->algorithm,
        );

        DB::transaction(function () use ($luckyDraw, $winners, $entrants) {
            $luckyDraw->forceFill([
                'drawn_at' => now(),
                'result'   => [
                    'winners'      => $winners,
                    'entrant_count' => $entrants->count(),
                    // Everything needed to recompute the result independently.
                    'algorithm'    => $luckyDraw->algorithm,
                    'seed'         => $luckyDraw->seed,
                    'seed_hash'    => $luckyDraw->seed_hash,
                ],
            ])->save();

            $luckyDraw->event->participants()
                ->whereIn('user_id', $winners)
                ->update(['status' => 'winner']);
        });

        $this->audit->log(
            $actor,
            'event.lucky_draw',
            'events',
            LuckyDraw::class,
            $luckyDraw->id,
            null,
            ['winners' => $winners, 'entrants' => $entrants->count(), 'seed_hash' => $luckyDraw->seed_hash],
        );

        return $luckyDraw->refresh();
    }

    /**
     * Deterministic winner selection.
     *
     * Weighted uses Efraimidis–Spirakis: each entrant gets a key `u^(1/w)` where `u` is a
     * uniform drawn deterministically from `hash(seed:user_id)`, then the top `n` keys
     * win. It is a standard, checkable algorithm rather than something invented here, and
     * being pure means anyone holding the seed gets the same answer.
     *
     * Static so a verifier can call it without touching the database.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    public static function selectWinners(string $seed, array $userIds, int $count, string $algorithm = 'random'): array
    {
        sort($userIds);

        $keyed = [];

        foreach ($userIds as $userId) {
            // 52 bits of the hash → a uniform in [0,1). Deterministic across machines.
            $digest = hash('sha256', $seed.':'.$userId);
            $uniform = hexdec(substr($digest, 0, 13)) / (float) (1 << 52);

            // Guard the log(0) edge; astronomically unlikely, still wrong if it happened.
            $uniform = $uniform <= 0.0 ? 1e-18 : $uniform;

            // Weight is 1 for a flat draw. A weighted draw would supply per-entrant
            // weights here; until entries can be bought there is nothing to weight by,
            // so the two modes coincide and that is stated rather than faked.
            $weight = 1.0;

            $keyed[$userId] = $uniform ** (1 / $weight);
        }

        arsort($keyed);

        return array_slice(array_keys($keyed), 0, $count);
    }

    /**
     * Recompute a published result from its seed — the check an outsider would run.
     *
     * @return array{valid: bool, hash_matches: bool, winners_match: bool, recomputed: array<int, int>}
     */
    public static function verify(LuckyDraw $luckyDraw): array
    {
        $result = $luckyDraw->result ?? [];
        $seed = $result['seed'] ?? $luckyDraw->seed;

        if ($seed === null || $luckyDraw->drawn_at === null) {
            return ['valid' => false, 'hash_matches' => false, 'winners_match' => false, 'recomputed' => []];
        }

        $hashMatches = hash('sha256', $seed) === $luckyDraw->seed_hash;

        $entrants = $luckyDraw->event->participants()->orderBy('user_id')->pluck('user_id')->all();

        $recomputed = self::selectWinners(
            $seed,
            $entrants,
            min($luckyDraw->winner_count, count($entrants)),
            $luckyDraw->algorithm,
        );

        $published = array_map('intval', $result['winners'] ?? []);
        $winnersMatch = $recomputed === $published;

        return [
            'valid'         => $hashMatches && $winnersMatch,
            'hash_matches'  => $hashMatches,
            'winners_match' => $winnersMatch,
            'recomputed'    => $recomputed,
        ];
    }
}
