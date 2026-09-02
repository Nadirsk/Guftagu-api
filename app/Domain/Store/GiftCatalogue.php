<?php

namespace App\Domain\Store;

use App\Models\Gift;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * GFT-062 — the gift catalogue the app reads, and the stock rules behind it.
 *
 * Two jobs that belong together:
 *
 *  1. **Cache with invalidation.** docs/02 §16 gives `cache:gifts:catalogue` a 600 s TTL.
 *     A.6a allows the app to lag by that TTL *or* to update immediately on invalidation —
 *     so every write through the admin panel flushes it, and the 10 minutes is only ever
 *     the worst case for a change made outside the panel.
 *
 *  2. **Stock that cannot oversell.** A.6b is explicit: "no oversell under concurrent
 *     sends". A read-then-write would let two simultaneous sends both see stock 1.
 *     `claimStock()` decrements conditionally in a single statement instead, so the
 *     database arbitrates.
 */
class GiftCatalogue
{
    public const CACHE_KEY = 'cache:gifts:catalogue';

    public const TTL = 600;

    /**
     * What the app should show. Sold-out and out-of-window gifts are excluded by the
     * query, not by a nightly job.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forApp(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL, function () {
            return Gift::query()
                ->available()
                ->with('category:id,key,name_en,name_hi')
                ->orderBy('sort_order')
                ->orderBy('coin_price')
                ->get()
                ->map(fn (Gift $gift) => [
                    'code'           => $gift->code,
                    'name_en'        => $gift->name_en,
                    'name_hi'        => $gift->name_hi,
                    'category'       => $gift->category?->key,
                    'tier'           => $gift->tier,
                    'coin_price'     => $gift->coin_price,
                    'diamond_value'  => $gift->diamond_value,
                    'thumbnail_url'  => $gift->thumbnail_url,
                    'animation_url'  => $gift->animation_url,
                    'animation_type' => $gift->animation_type,
                    'duration_ms'    => $gift->duration_ms,
                    'is_fullscreen'  => $gift->is_fullscreen,
                    'max_combo'      => $gift->is_combo_enabled ? $gift->max_combo : 1,
                    'vip_tier'       => $gift->required_vip_tier_id,
                    'stock'          => $gift->stock,
                ])
                ->values()
                ->all();
        });
    }

    /** Called after every catalogue write, so the panel and the app never disagree. */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Take `$quantity` off a limited gift's stock, or fail.
     *
     * A.6b — the whole point is that this is safe under concurrency. The condition lives
     * in the UPDATE's WHERE clause, so two racing sends cannot both succeed on the last
     * unit: MySQL applies one and the other matches zero rows.
     *
     * Unlimited gifts (`stock` NULL) always succeed and touch nothing.
     *
     * @return bool true when the stock was claimed
     */
    public function claimStock(Gift $gift, int $quantity = 1): bool
    {
        if ($quantity < 1) {
            return false;
        }

        if (! $gift->is_limited || $gift->stock === null) {
            return true;
        }

        $claimed = DB::table('gifts')
            ->where('id', $gift->id)
            ->where('stock', '>=', $quantity)
            ->update([
                'stock'      => DB::raw("stock - {$quantity}"),
                'updated_at' => now(),
            ]);

        if ($claimed > 0) {
            $this->flush();

            return true;
        }

        return false;
    }

    /** Returning stock after a failed send, for the same reason. */
    public function releaseStock(Gift $gift, int $quantity = 1): void
    {
        if (! $gift->is_limited || $gift->stock === null || $quantity < 1) {
            return;
        }

        DB::table('gifts')
            ->where('id', $gift->id)
            ->update(['stock' => DB::raw("stock + {$quantity}"), 'updated_at' => now()]);

        $this->flush();
    }
}
