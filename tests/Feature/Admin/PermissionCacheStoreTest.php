<?php

namespace Tests\Feature\Admin;

use App\Domain\Access\Services\PermissionCache;
use App\Domain\Access\Services\PermissionResolver;
use App\Domain\Moderation\BanPolicy;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The permission cache must work on **any** cache store.
 *
 * This exists because it did not. The three caches keyed per admin — the effective
 * permission set, the scope dimensions and the ban cap — were grouped with
 * `Cache::tags(["perm:{$id}"])`, and tagging is supported only by `redis`, `memcached`,
 * `array` and `dynamodb`. On `database` or `file` the very first permission check threw
 * `BadMethodCallException: This cache store does not support tagging`, which is to say
 * every authenticated request 500'd.
 *
 * The suite did not catch it because phpunit.xml sets `CACHE_STORE=array`, which *does*
 * tag. So these tests deliberately run against `database` — the Laravel default, and the
 * store any environment without Redis falls back to.
 */
class PermissionCacheStoreTest extends TestCase
{
    use RefreshDatabase;

    /** Stores that cannot tag. `array` is covered by every other test in the suite. */
    public static function nonTaggableStores(): array
    {
        return [
            'database' => ['database'],
            'file'     => ['file'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    protected function moderator(): AdminUser
    {
        static $seq = 0;
        $seq++;

        return AdminUser::create([
            'name'     => "Mod {$seq}",
            'email'    => "mod{$seq}@test.local",
            'password' => 'Password12345',
            'role_id'  => Role::where('key', Role::MODERATOR)->value('id'),
            'status'   => 'active',
        ]);
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('nonTaggableStores')]
    public function permissions_resolve_on_a_store_that_cannot_tag(string $store): void
    {
        config(['cache.default' => $store]);
        Cache::flush();

        $admin = $this->moderator();

        // Before the fix this line threw BadMethodCallException.
        $keys = app(PermissionResolver::class)->effectiveFor($admin);

        $this->assertNotEmpty($keys, 'A Moderator should inherit a role baseline.');
        $this->assertTrue(app(PermissionResolver::class)->has($admin, 'reports.view'));
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('nonTaggableStores')]
    public function the_ban_cap_resolves_on_a_store_that_cannot_tag(string $store): void
    {
        config(['cache.default' => $store]);
        Cache::flush();

        $this->assertNull(app(BanPolicy::class)->maxBanHours($this->moderator()));
    }

    #[Test]
    public function a_flush_invalidates_every_cached_value_for_that_admin(): void
    {
        // The whole point of the tag was that one flush cleared permissions, scopes *and*
        // the ban cap together. The generation counter has to keep that property.
        config(['cache.default' => 'database']);
        Cache::flush();

        $admin = $this->moderator();
        $cache = app(PermissionCache::class);

        $cache->remember($admin->id, 'effective', fn () => 'first');
        $cache->remember($admin->id, 'scope:agencies', fn () => 'first');
        $cache->remember($admin->id, 'ban_cap', fn () => 'first');

        $this->assertSame('first', $cache->remember($admin->id, 'scope:agencies', fn () => 'second'));

        $cache->flush($admin->id);

        foreach (['effective', 'scope:agencies', 'ban_cap'] as $key) {
            $this->assertSame(
                'second',
                $cache->remember($admin->id, $key, fn () => 'second'),
                "`{$key}` survived the flush.",
            );
        }
    }

    #[Test]
    public function one_admins_flush_leaves_another_admins_cache_alone(): void
    {
        config(['cache.default' => 'database']);
        Cache::flush();

        $a = $this->moderator();
        $b = $this->moderator();
        $cache = app(PermissionCache::class);

        $cache->remember($a->id, 'effective', fn () => 'a-value');
        $cache->remember($b->id, 'effective', fn () => 'b-value');

        $cache->flush($a->id);

        $this->assertSame('fresh', $cache->remember($a->id, 'effective', fn () => 'fresh'));
        $this->assertSame('b-value', $cache->remember($b->id, 'effective', fn () => 'fresh'));
    }

    #[Test]
    public function a_revoked_permission_stops_working_immediately(): void
    {
        // A.11 — "the cache does not delay enforcement". The same criterion the tag existed
        // for, now asserted against a store that cannot tag.
        config(['cache.default' => 'database']);
        Cache::flush();

        $admin = $this->moderator();
        $resolver = app(PermissionResolver::class);
        $permission = Permission::where('key', 'agency.view')->firstOrFail();

        $this->assertFalse($resolver->has($admin, 'agency.view'));

        $admin->directGrants()->attach($permission->id, ['effect' => 'allow', 'granted_by' => $admin->id]);
        $resolver->flushFor($admin->id);

        $this->assertTrue($resolver->has($admin->fresh(), 'agency.view'));

        $admin->directGrants()->detach($permission->id);
        $resolver->flushFor($admin->id);

        $this->assertFalse($resolver->has($admin->fresh(), 'agency.view'));
    }
}
