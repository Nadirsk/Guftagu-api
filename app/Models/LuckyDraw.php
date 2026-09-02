<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/02 §9 — A.9a, provable fairness.
 *
 * The commitment only means something if the seed is genuinely unavailable until the draw
 * has run. `$hidden` therefore covers `seed` unconditionally, and the one method that
 * returns it checks `drawn_at` first — so a controller cannot leak it by forgetting to,
 * and neither can an accidental `->toArray()`.
 */
class LuckyDraw extends Model
{
    protected $fillable = [
        'event_id', 'draw_at', 'prize_pool', 'winner_count',
        'algorithm', 'seed_hash', 'seed', 'drawn_at', 'result',
    ];

    /** Never serialised. Use revealedSeed(), which enforces the timing. */
    protected $hidden = ['seed'];

    protected function casts(): array
    {
        return [
            'draw_at'      => 'datetime',
            'drawn_at'     => 'datetime',
            'prize_pool'   => 'array',
            'result'       => 'array',
            'winner_count' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function hasRun(): bool
    {
        return $this->drawn_at !== null;
    }

    /**
     * The seed, but only once the draw has happened.
     *
     * Before that it returns null — publishing it early would let anyone compute the
     * winners in advance, which is exactly what the hash-first commitment prevents.
     */
    public function revealedSeed(): ?string
    {
        return $this->hasRun() ? $this->seed : null;
    }
}
