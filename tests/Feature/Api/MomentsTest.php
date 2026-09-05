<?php

namespace Tests\Feature\Api;

use App\Domain\Moderation\ContentFilter;
use App\Events\Posts\PostCommented;
use App\Events\Posts\PostCreated;
use App\Events\Posts\PostLiked;
use App\Models\BannedWord;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/** Epic D.3d acceptance criteria — moments: visibility, likes, comments, realtime. */
class MomentsTest extends MobileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(ContentFilter::class)->flush();
    }

    protected function makePost(User $author, string $visibility = Post::PUBLIC, string $body = 'Hello'): Post
    {
        return Post::create([
            'user_id'    => $author->id,
            'type'       => Post::TEXT,
            'body'       => $body,
            'visibility' => $visibility,
        ]);
    }

    // ------------------------------------------------------------------ writing

    #[Test]
    public function a_moment_can_be_posted_and_appears_in_the_authors_own_feed(): void
    {
        $me = $this->actingAsUser($this->makeUser('Author'));

        $this->postJson("{$this->base}/posts", ['body' => 'First moment'])
            ->assertStatus(201)
            ->assertJsonPath('data.post.body', 'First moment')
            ->assertJsonPath('data.post.visibility', Post::PUBLIC)
            ->assertJsonPath('data.post.author.uuid', $me->uuid);

        $this->assertSame(1, Post::where('user_id', $me->id)->count());

        $feed = $this->getJson("{$this->base}/feed")->assertOk()->json('data');
        $this->assertCount(1, $feed);
    }

    #[Test]
    public function a_post_with_neither_text_nor_media_is_refused(): void
    {
        $this->actingAsUser($this->makeUser('Author'));

        $this->postJson("{$this->base}/posts", ['body' => '   '])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function a_banned_word_blocks_the_post(): void
    {
        BannedWord::create([
            'word' => 'forbidden', 'language' => 'any', 'severity' => 'block', 'is_active' => true,
        ]);
        app(ContentFilter::class)->flush();

        $this->actingAsUser($this->makeUser('Author'));

        $this->postJson("{$this->base}/posts", ['body' => 'this is forbidden text'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BANNED_WORD_DETECTED');

        $this->assertSame(0, Post::count());
    }

    #[Test]
    public function only_the_author_can_delete_a_moment(): void
    {
        $author = $this->makeUser('Author');
        $post = $this->makePost($author);

        $this->actingAsUser($this->makeUser('Stranger'));
        $this->deleteJson("{$this->base}/posts/{$post->uuid}")->assertStatus(404);

        $this->actingAsUser($author);
        $this->deleteJson("{$this->base}/posts/{$post->uuid}")->assertOk();

        $this->assertSoftDeleted('posts', ['id' => $post->id]);
    }

    // --------------------------------------------------------------- visibility

    #[Test]
    public function a_followers_only_moment_is_invisible_to_a_non_follower_in_the_feed(): void
    {
        // D.3d, first half.
        $author = $this->makeUser('Author');
        $this->makePost($author, Post::FOLLOWERS, 'Only for followers');

        $stranger = $this->actingAsUser($this->makeUser('Stranger'));

        $this->assertEmpty($this->getJson("{$this->base}/feed?scope=public")->json('data'));

        $this->follow($stranger, $author);

        $feed = $this->getJson("{$this->base}/feed")->assertOk()->json('data');
        $this->assertCount(1, $feed);
        $this->assertSame('Only for followers', $feed[0]['body']);
    }

    #[Test]
    public function a_followers_only_moment_is_invisible_to_a_non_follower_by_direct_id(): void
    {
        // D.3d, second half — "or by direct id". A 404, not a 403: a 403 confirms it exists.
        $author = $this->makeUser('Author');
        $post = $this->makePost($author, Post::FOLLOWERS);

        $this->actingAsUser($this->makeUser('Stranger'));

        $this->getJson("{$this->base}/posts/{$post->uuid}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function a_private_moment_is_visible_to_nobody_but_its_author(): void
    {
        $author = $this->makeUser('Author');
        $post = $this->makePost($author, Post::PRIVATE);

        $follower = $this->actingAsUser($this->makeUser('Follower'));
        $this->follow($follower, $author);

        $this->getJson("{$this->base}/posts/{$post->uuid}")->assertStatus(404);

        $this->actingAsUser($author);
        $this->getJson("{$this->base}/posts/{$post->uuid}")->assertOk();
    }

    #[Test]
    public function a_hidden_moment_stays_visible_to_its_author_and_nobody_else(): void
    {
        $author = $this->makeUser('Author');
        $post = $this->makePost($author);
        $post->forceFill(['is_hidden' => true, 'hidden_reason' => 'under review'])->save();

        $this->actingAsUser($this->makeUser('Reader'));
        $this->getJson("{$this->base}/posts/{$post->uuid}")->assertStatus(404);

        $this->actingAsUser($author);
        $this->getJson("{$this->base}/posts/{$post->uuid}")->assertOk();
    }

    #[Test]
    public function a_blocked_authors_moments_disappear_from_the_feed(): void
    {
        $author = $this->makeUser('Author');
        $this->makePost($author);

        $me = $this->actingAsUser($this->makeUser('Me'));
        $this->follow($me, $author);

        $this->assertCount(1, $this->getJson("{$this->base}/feed")->json('data'));

        $this->postJson("{$this->base}/users/{$author->uuid}/block")->assertOk();

        $this->assertEmpty($this->getJson("{$this->base}/feed")->json('data'));
    }

    // -------------------------------------------------------------------- likes

    #[Test]
    public function liking_is_idempotent_and_the_counter_is_authoritative(): void
    {
        $author = $this->makeUser('Author');
        $post = $this->makePost($author);

        $this->actingAsUser($this->makeUser('Liker'));

        $this->postJson("{$this->base}/posts/{$post->uuid}/like")
            ->assertOk()
            ->assertJsonPath('data.post.like_count', 1)
            ->assertJsonPath('data.post.liked_by_me', true);

        $this->postJson("{$this->base}/posts/{$post->uuid}/like")
            ->assertOk()
            ->assertJsonPath('data.post.like_count', 1);

        $this->assertSame(1, $post->fresh()->like_count);
    }

    #[Test]
    public function unliking_decrements_and_never_goes_negative(): void
    {
        $post = $this->makePost($this->makeUser('Author'));

        $this->actingAsUser($this->makeUser('Liker'));

        $this->deleteJson("{$this->base}/posts/{$post->uuid}/like")
            ->assertOk()
            ->assertJsonPath('data.post.like_count', 0);

        $this->postJson("{$this->base}/posts/{$post->uuid}/like")->assertOk();
        $this->deleteJson("{$this->base}/posts/{$post->uuid}/like")
            ->assertOk()
            ->assertJsonPath('data.post.like_count', 0);

        $this->assertSame(0, $post->fresh()->like_count);
    }

    #[Test]
    public function a_like_broadcasts_to_the_post_thread_and_to_the_author(): void
    {
        Event::fake([PostLiked::class]);

        $author = $this->makeUser('Author');
        $post = $this->makePost($author);
        $this->actingAsUser($this->makeUser('Liker'));

        $this->postJson("{$this->base}/posts/{$post->uuid}/like")->assertOk();

        Event::assertDispatched(PostLiked::class, function (PostLiked $e) use ($post, $author) {
            $channels = array_map(fn ($c) => (string) $c, $e->broadcastOn());

            return $e->liked
                && in_array("private-post.{$post->uuid}", $channels, true)
                && in_array("private-user.{$author->uuid}", $channels, true);
        });
    }

    #[Test]
    public function liking_your_own_moment_does_not_notify_you(): void
    {
        Event::fake([PostLiked::class]);

        $author = $this->actingAsUser($this->makeUser('Author'));
        $post = $this->makePost($author);

        $this->postJson("{$this->base}/posts/{$post->uuid}/like")->assertOk();

        Event::assertDispatched(PostLiked::class, function (PostLiked $e) use ($post, $author) {
            $channels = array_map(fn ($c) => (string) $c, $e->broadcastOn());

            return $channels === ["private-post.{$post->uuid}"]
                && ! in_array("private-user.{$author->uuid}", $channels, true);
        });

        $this->assertDatabaseMissing('notifications', ['user_id' => $author->id, 'type' => 'post.liked']);
    }

    #[Test]
    public function you_cannot_like_a_moment_you_cannot_see(): void
    {
        $post = $this->makePost($this->makeUser('Author'), Post::FOLLOWERS);

        $this->actingAsUser($this->makeUser('Stranger'));

        $this->postJson("{$this->base}/posts/{$post->uuid}/like")->assertStatus(404);
    }

    // ----------------------------------------------------------------- comments

    #[Test]
    public function commenting_moves_the_counter_and_broadcasts(): void
    {
        Event::fake([PostCommented::class]);

        $author = $this->makeUser('Author');
        $post = $this->makePost($author);
        $commenter = $this->actingAsUser($this->makeUser('Commenter'));

        $this->postJson("{$this->base}/posts/{$post->uuid}/comments", ['body' => 'Nice one'])
            ->assertStatus(201)
            ->assertJsonPath('data.comment.body', 'Nice one')
            ->assertJsonPath('data.comment.author.uuid', $commenter->uuid)
            ->assertJsonPath('data.comment_count', 1);

        Event::assertDispatched(PostCommented::class);

        $this->assertDatabaseHas('notifications', ['user_id' => $author->id, 'type' => 'post.commented']);
    }

    #[Test]
    public function a_reply_to_a_reply_attaches_to_the_top_level_comment(): void
    {
        $post = $this->makePost($this->makeUser('Author'));
        $this->actingAsUser($this->makeUser('Commenter'));

        $top = $this->postJson("{$this->base}/posts/{$post->uuid}/comments", ['body' => 'Top'])
            ->json('data.comment.uuid');

        $reply = $this->postJson("{$this->base}/posts/{$post->uuid}/comments", [
            'body' => 'Reply', 'parent_uuid' => $top,
        ])->json('data.comment.uuid');

        // Replying to the reply must not nest a third level — the client renders two.
        $this->postJson("{$this->base}/posts/{$post->uuid}/comments", [
            'body' => 'Reply to reply', 'parent_uuid' => $reply,
        ])->assertStatus(201);

        $topId = PostComment::where('uuid', $top)->value('id');

        $this->assertSame(2, PostComment::where('parent_id', $topId)->count());
    }

    #[Test]
    public function a_reply_cannot_point_at_a_comment_on_another_post(): void
    {
        $postA = $this->makePost($this->makeUser('A'));
        $postB = $this->makePost($this->makeUser('B'));

        $this->actingAsUser($this->makeUser('Commenter'));

        $onB = $this->postJson("{$this->base}/posts/{$postB->uuid}/comments", ['body' => 'on B'])
            ->json('data.comment.uuid');

        $this->postJson("{$this->base}/posts/{$postA->uuid}/comments", [
            'body' => 'cross-post', 'parent_uuid' => $onB,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_deleted_comment_becomes_a_tombstone_rather_than_disappearing(): void
    {
        $post = $this->makePost($this->makeUser('Author'));
        $this->actingAsUser($this->makeUser('Commenter'));

        $uuid = $this->postJson("{$this->base}/posts/{$post->uuid}/comments", ['body' => 'Oops'])
            ->json('data.comment.uuid');

        $this->deleteJson("{$this->base}/posts/{$post->uuid}/comments/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.comment_count', 0);

        $rows = $this->getJson("{$this->base}/posts/{$post->uuid}/comments")->json('data');

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['is_deleted']);
        $this->assertNull($rows[0]['body']);
    }

    #[Test]
    public function the_post_author_may_remove_somebody_elses_comment(): void
    {
        $author = $this->makeUser('Author');
        $post = $this->makePost($author);

        $this->actingAsUser($this->makeUser('Commenter'));
        $uuid = $this->postJson("{$this->base}/posts/{$post->uuid}/comments", ['body' => 'Spam'])
            ->json('data.comment.uuid');

        $this->actingAsUser($this->makeUser('Stranger'));
        $this->deleteJson("{$this->base}/posts/{$post->uuid}/comments/{$uuid}")->assertStatus(404);

        $this->actingAsUser($author);
        $this->deleteJson("{$this->base}/posts/{$post->uuid}/comments/{$uuid}")->assertOk();
    }

    #[Test]
    public function a_comment_cannot_be_deleted_through_a_different_posts_url(): void
    {
        $postA = $this->makePost($this->makeUser('A'));
        $author = $this->makeUser('B');
        $postB = $this->makePost($author);

        $this->actingAsUser($author);
        $uuid = $this->postJson("{$this->base}/posts/{$postB->uuid}/comments", ['body' => 'mine'])
            ->json('data.comment.uuid');

        $this->deleteJson("{$this->base}/posts/{$postA->uuid}/comments/{$uuid}")->assertStatus(404);
    }

    // ------------------------------------------------------------------ realtime

    #[Test]
    public function a_public_moment_broadcasts_on_the_shared_feed_channel(): void
    {
        Event::fake([PostCreated::class]);

        $this->actingAsUser($this->makeUser('Author'));
        $this->postJson("{$this->base}/posts", ['body' => 'Public', 'visibility' => Post::PUBLIC])->assertStatus(201);

        Event::assertDispatched(PostCreated::class, fn (PostCreated $e) => array_map(
            fn ($c) => (string) $c, $e->broadcastOn()
        ) === ['feed']);
    }

    #[Test]
    public function a_followers_only_moment_fans_out_to_followers_and_not_to_the_feed(): void
    {
        Event::fake([PostCreated::class]);

        $author = $this->makeUser('Author');
        $follower = $this->makeUser('Follower');
        $this->makeUser('Stranger');
        $this->follow($follower, $author);

        $this->actingAsUser($author);
        $this->postJson("{$this->base}/posts", ['body' => 'Followers', 'visibility' => Post::FOLLOWERS])
            ->assertStatus(201);

        Event::assertDispatched(PostCreated::class, function (PostCreated $e) use ($follower) {
            $channels = array_map(fn ($c) => (string) $c, $e->broadcastOn());

            return $channels === ["private-user.{$follower->uuid}"];
        });
    }

    #[Test]
    public function a_private_moment_broadcasts_nowhere(): void
    {
        Event::fake([PostCreated::class]);

        $this->actingAsUser($this->makeUser('Author'));
        $this->postJson("{$this->base}/posts", ['body' => 'Secret', 'visibility' => Post::PRIVATE])->assertStatus(201);

        Event::assertDispatched(PostCreated::class, fn (PostCreated $e) => $e->broadcastOn() === []);
    }

    // ----------------------------------------------------------------- paging

    #[Test]
    public function the_feed_pages_by_cursor_without_repeating_a_row(): void
    {
        $me = $this->actingAsUser($this->makeUser('Me'));

        foreach (range(1, 5) as $i) {
            $this->makePost($me, Post::PUBLIC, "post-{$i}");
        }

        $first = $this->getJson("{$this->base}/feed?limit=2")->assertOk();
        $this->assertTrue($first->json('meta.has_more'));
        $this->assertCount(2, $first->json('data'));

        $second = $this->getJson("{$this->base}/feed?limit=2&cursor=".$first->json('meta.next_cursor'))->assertOk();

        $seen = array_merge(
            array_column($first->json('data'), 'uuid'),
            array_column($second->json('data'), 'uuid'),
        );

        $this->assertCount(4, array_unique($seen));
    }
}
