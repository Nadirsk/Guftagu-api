<?php

namespace Tests\Feature\Api;

use App\Models\AdminUser;
use App\Models\Post;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * docs/01 §4.3 — channel authorisation.
 *
 * `/broadcasting/auth` is the second door into every piece of data this module serves. The
 * REST rules are tested elsewhere; these assert that the socket does not quietly offer a
 * way around them — a `followers`-only post that 404s over HTTP but authorises over the
 * WebSocket is still leaked.
 *
 * One endpoint answers both guards, so the cross-consumer cases matter as much as the
 * within-app ones: an admin must not authorise a `user.{uuid}` channel by sharing an id
 * with an app user, and an app user must not reach `admin.*` at all.
 */
class BroadcastChannelTest extends MobileTestCase
{
    /**
     * Two things make this setup necessary, and both would otherwise fail silently and
     * green.
     *
     * 1. **The `log` driver authorises everything.** `LogBroadcaster::auth()` returns true
     *    unconditionally — it has no signature to produce and nobody to refuse. On the
     *    suite's default connection every assertion in this file would pass without a
     *    single channel callback ever running.
     *
     * 2. **Channels bind to whichever broadcaster was default when they were registered.**
     *    `Broadcast::channel()` forwards through `BroadcastManager::__call()` to
     *    `driver()`, and `routes/channels.php` is required once, at boot. Switching the
     *    connection alone therefore leaves the callbacks on the `log` instance while auth
     *    runs against a `reverb` instance that knows no channels at all — which denies
     *    everything, the same wrong-reason result inverted.
     *
     * So: switch the connection, then re-require the channel file so the callbacks land on
     * the broadcaster that will actually answer. Signing is offline, so no socket server is
     * needed — the credentials only have to exist.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default'                   => 'reverb',
            'broadcasting.connections.reverb.key'    => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        require base_path('routes/channels.php');
    }

    protected function authorise(string $channel): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/broadcasting/auth', [
            'socket_id'    => '123.456',
            'channel_name' => $channel,
        ]);
    }

    #[Test]
    public function a_user_may_subscribe_to_their_own_channel_only(): void
    {
        $me = $this->actingAsUser($this->makeUser('Me'));
        $other = $this->makeUser('Other');

        $this->authorise("private-user.{$me->uuid}")->assertOk();
        $this->authorise("private-user.{$other->uuid}")->assertForbidden();
    }

    #[Test]
    public function a_post_channel_follows_the_same_visibility_rule_as_the_rest_endpoint(): void
    {
        $author = $this->makeUser('Author');

        $publicPost = Post::create([
            'user_id' => $author->id, 'type' => Post::TEXT, 'body' => 'open', 'visibility' => Post::PUBLIC,
        ]);
        $followersPost = Post::create([
            'user_id' => $author->id, 'type' => Post::TEXT, 'body' => 'closed', 'visibility' => Post::FOLLOWERS,
        ]);

        $stranger = $this->actingAsUser($this->makeUser('Stranger'));

        $this->authorise("private-post.{$publicPost->uuid}")->assertOk();
        $this->authorise("private-post.{$followersPost->uuid}")->assertForbidden();

        // Following flips exactly one of them, and nothing else.
        $this->follow($stranger, $author);
        $this->authorise("private-post.{$followersPost->uuid}")->assertOk();
    }

    #[Test]
    public function a_conversation_channel_is_participants_only(): void
    {
        $a = $this->makeUser('A');
        $b = $this->makeUser('B');
        $this->befriend($a, $b);

        $this->actingAsUser($a);
        $uuid = $this->postJson("{$this->base}/conversations", ['user_uuid' => $b->uuid])
            ->assertStatus(201)
            ->json('data.conversation.uuid');

        $this->authorise("private-conversation.{$uuid}")->assertOk();

        $this->actingAsUser($this->makeUser('Outsider'));
        $this->authorise("private-conversation.{$uuid}")->assertForbidden();
    }

    #[Test]
    public function a_block_closes_the_conversation_channel_for_both_sides(): void
    {
        $a = $this->makeUser('A');
        $b = $this->makeUser('B');
        $this->befriend($a, $b);

        $this->actingAsUser($a);
        $uuid = $this->postJson("{$this->base}/conversations", ['user_uuid' => $b->uuid])
            ->json('data.conversation.uuid');

        $this->postJson("{$this->base}/users/{$b->uuid}/block")->assertOk();

        $this->authorise("private-conversation.{$uuid}")->assertForbidden();

        $this->actingAsUser($b);
        $this->authorise("private-conversation.{$uuid}")->assertForbidden();
    }

    #[Test]
    public function an_app_user_cannot_reach_an_admin_channel(): void
    {
        $this->actingAsUser($this->makeUser('Me'));

        $this->authorise('private-admin.moderation')->assertForbidden();
        $this->authorise('private-admin.dashboard')->assertForbidden();
    }

    #[Test]
    public function an_admin_cannot_reach_a_users_channel_by_sharing_an_id(): void
    {
        // The two tables have separate id spaces. A callback that only compared ids would
        // hand this admin the app user's private channel.
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $user = $this->makeUser('App User');

        $admin = AdminUser::create([
            'name' => 'Super', 'email' => 'super@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::SUPER_ADMIN)->value('id'), 'status' => 'active',
        ]);

        Sanctum::actingAs($admin, ['*'], 'sanctum-admin');

        $this->authorise("private-user.{$user->uuid}")->assertForbidden();
        // The admin's own channel still works, so this is not simply "everything denied".
        $this->authorise('private-admin.dashboard')->assertOk();
    }

    #[Test]
    public function an_unauthenticated_caller_gets_nothing(): void
    {
        $user = $this->makeUser('Me');

        $this->authorise("private-user.{$user->uuid}")->assertUnauthorized();
    }
}
