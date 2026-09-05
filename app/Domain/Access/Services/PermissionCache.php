<?php

namespace App\Domain\Access\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * The per-admin cache used by {@see PermissionResolver}, {@see ScopeFilter} and
 * {@see \App\Domain\Moderation\BanPolicy} — three groups of keys that must all disappear
 * the moment a grant changes (A.11: "the cache does not delay enforcement").
 *
 * **This existed as `Cache::tags(["perm:{$id}"])` and could not stay that way.** Only
 * `redis`, `memcached`, `array` and `dynamodb` support tagging; on `database` or `file` the
 * call throws `BadMethodCallException: This cache store does not support tagging`. That is
 * not a hypothetical — Laravel's own default store is `database`, and any environment
 * without Redis (a fresh Windows checkout, most CI containers) breaks on the first
 * permission check, which is to say on every authenticated request.
 *
 * So invalidation is a **generation counter** instead. Every key carries the admin's current
 * generation; flushing increments it, and the old keys become unreachable at once and expire
 * on their own TTL. Two properties matter:
 *
 *  - It works on every store, including the ones that cannot tag.
 *  - Flushing is one `increment`, not a scan. Tag flushing on Redis is not free either.
 *
 * The trade is that superseded entries occupy memory until their TTL lapses. At 300 seconds
 * and a handful of keys per admin, that is not a number worth optimising.
 */
class PermissionCache
{
    /** Seconds. docs/02 §16. */
    public const TTL = 300;

    /**
     * Read through the cache under `$key`, scoped to this admin's current generation.
     *
     * `$key` must be unique per *kind* of value, not per admin — the admin id is already
     * part of the namespace. Passing "scope:agency" is right; "scope:agency:7" merely makes
     * the key longer.
     */
    public function remember(int $adminId, string $key, Closure $callback, ?int $ttl = null): mixed
    {
        return Cache::remember(
            $this->qualify($adminId, $key),
            $ttl ?? self::TTL,
            $callback,
        );
    }

    /**
     * Invalidate everything cached for one admin.
     *
     * Incrementing the generation orphans every key that carried the old one, whatever
     * prefix it used, which is exactly what the tag was for.
     */
    public function flush(int $adminId): void
    {
        $key = $this->generationKey($adminId);

        // `increment` returns false when the key is absent on some stores rather than
        // starting at 1, so seed it first. A missed increment would leave stale permissions
        // readable for the rest of the TTL — the one failure this class exists to prevent.
        if (! Cache::has($key)) {
            Cache::forever($key, 1);

            return;
        }

        Cache::increment($key);
    }

    /** `perm:7:v3:scope:agency` */
    protected function qualify(int $adminId, string $key): string
    {
        return "perm:{$adminId}:v{$this->generation($adminId)}:{$key}";
    }

    /**
     * Never expires: if the generation lapsed while a cached value outlived it, the counter
     * would roll back to 1 and resurrect entries that were flushed.
     */
    protected function generation(int $adminId): int
    {
        return (int) Cache::rememberForever($this->generationKey($adminId), fn () => 1);
    }

    protected function generationKey(int $adminId): string
    {
        return "perm:{$adminId}:gen";
    }
}
