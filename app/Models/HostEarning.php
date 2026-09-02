<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/02 §10 — the daily rollup every earnings screen reads (GFT-084).
 *
 * A rollup, not a ledger: it is derived from `diamond_transactions` and can always be
 * rebuilt from them. `HostEarningsRollup::verify()` is what proves it still matches, which
 * is what A.8c actually asks for.
 */
class HostEarning extends Model
{
    protected $fillable = [
        'host_id', 'date', 'diamonds_earned', 'gross_paise', 'platform_cut_paise',
        'agency_cut_paise', 'net_paise', 'room_hours', 'gift_count', 'unique_gifters',
    ];

    protected function casts(): array
    {
        return [
            'date'               => 'date',
            'diamonds_earned'    => 'integer',
            'gross_paise'        => 'integer',
            'platform_cut_paise' => 'integer',
            'agency_cut_paise'   => 'integer',
            'net_paise'          => 'integer',
            'room_hours'         => 'integer',
            'gift_count'         => 'integer',
            'unique_gifters'     => 'integer',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }
}
