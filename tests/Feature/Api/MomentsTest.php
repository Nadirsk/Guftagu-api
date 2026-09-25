<?php

namespace Tests\Feature\Api;

use App\Domain\Moderation\ContentFilter;
use App\Events\Posts\PostCommented;
use App\Events\Posts\PostCreated;
use App\Events\Posts\PostLiked;
use App\Http\Controllers\Api\MediaController;
use App\Models\BannedWord;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
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
    public function liking_your_own_moment_notifies_you(): void
    {
        Event::fake([PostLiked::class]);

        $author = $this->actingAsUser($this->makeUser('Author'));
        $post = $this->makePost($author);

        $this->postJson("{$this->base}/posts/{$post->uuid}/like")->assertOk();

        Event::assertDispatched(PostLiked::class, function (PostLiked $e) use ($post, $author) {
            $channels = array_map(fn ($c) => (string) $c, $e->broadcastOn());

            return $channels === ["private-post.{$post->uuid}"];
        });

        $this->assertDatabaseHas('notifications', ['user_id' => $author->id, 'type' => 'post.liked']);
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

    // ------------------------------------------------- feed scopes (app tabs)

    #[Test]
    public function the_discover_scope_hides_people_you_already_follow_and_yourself(): void
    {
        $me = $this->actingAsUser($this->makeUser('Me'));
        $followed = $this->makeUser('Followed');
        $stranger = $this->makeUser('Stranger');

        $this->follow($me, $followed);

        $mine = $this->makePost($me, Post::PUBLIC, 'mine');
        $theirs = $this->makePost($followed, Post::PUBLIC, 'followed');
        $other = $this->makePost($stranger, Post::PUBLIC, 'stranger');

        $discover = array_column($this->getJson("{$this->base}/feed?scope=discover")->assertOk()->json('data'), 'uuid');

        $this->assertSame([$other->uuid], $discover);
        $this->assertNotContains($mine->uuid, $discover);
        $this->assertNotContains($theirs->uuid, $discover);
    }

    #[Test]
    public function following_and_discover_never_show_the_same_moment(): void
    {
        $me = $this->actingAsUser($this->makeUser('Me'));
        $followed = $this->makeUser('Followed');
        $stranger = $this->makeUser('Stranger');

        $this->follow($me, $followed);
        $this->makePost($followed, Post::PUBLIC, 'followed');
        $this->makePost($stranger, Post::PUBLIC, 'stranger');

        $following = array_column($this->getJson("{$this->base}/feed?scope=following")->assertOk()->json('data'), 'uuid');
        $discover = array_column($this->getJson("{$this->base}/feed?scope=discover")->assertOk()->json('data'), 'uuid');

        $this->assertSame([], array_intersect($following, $discover));
    }

    #[Test]
    public function following_someone_moves_their_moment_between_the_two_tabs(): void
    {
        $this->actingAsUser($this->makeUser('Me'));
        $author = $this->makeUser('Author');
        $post = $this->makePost($author, Post::PUBLIC, 'theirs');

        $this->assertSame(
            [$post->uuid],
            array_column($this->getJson("{$this->base}/feed?scope=discover")->json('data'), 'uuid'),
        );

        $this->postJson("{$this->base}/users/{$author->uuid}/follow")->assertOk();

        $this->assertSame([], $this->getJson("{$this->base}/feed?scope=discover")->json('data'));
        $this->assertSame(
            [$post->uuid],
            array_column($this->getJson("{$this->base}/feed?scope=following")->json('data'), 'uuid'),
        );
    }

    #[Test]
    public function an_unknown_feed_scope_is_refused(): void
    {
        $this->actingAsUser($this->makeUser('Me'));

        $this->getJson("{$this->base}/feed?scope=nonsense")->assertStatus(422);
    }

    // ------------------------------------------------------------------ likers

    #[Test]
    public function the_likes_list_names_who_liked_the_post_newest_first(): void
    {
        $author = $this->makeUser('Author');
        $first  = $this->makeUser('First');
        $second = $this->makeUser('Second');

        $this->actingAsUser($author);
        $post = $this->postJson("{$this->base}/posts", ['type' => Post::TEXT, 'body' => 'Hi'])
            ->json('data.post.uuid');

        $this->actingAsUser($first);
        $this->postJson("{$this->base}/posts/{$post}/like")->assertOk();
        $this->actingAsUser($second);
        $this->postJson("{$this->base}/posts/{$post}/like")->assertOk();

        $this->actingAsUser($author);
        $names = $this->getJson("{$this->base}/posts/{$post}/likes")
            ->assertOk()
            ->json('data.*.display_name');

        // `makeUser` makes each display name unique, so compare against what it made.
        $this->assertSame(
            [$second->profile->display_name, $first->profile->display_name],
            $names,
        );
    }

    #[Test]
    public function a_post_nobody_liked_has_an_empty_likes_list(): void
    {
        $author = $this->makeUser('Author');
        $this->actingAsUser($author);
        $post = $this->postJson("{$this->base}/posts", ['type' => Post::TEXT, 'body' => 'Hi'])
            ->json('data.post.uuid');

        $this->getJson("{$this->base}/posts/{$post}/likes")->assertOk()->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------ media upload

    #[Test]
    public function an_image_upload_returns_a_url_and_its_kind(): void
    {
        $disk = config('filesystems.uploads_disk', 'public');
        Storage::fake($disk);
        $this->actingAsUser($this->makeUser('Me'));

        $response = $this->post("{$this->base}/media", [
            'file' => UploadedFile::fake()->image('shot.jpg'),
        ])->assertOk();

        $this->assertSame('image', $response->json('data.kind'));
        $this->assertNotEmpty($response->json('data.url'));
        Storage::disk($disk)->assertExists($response->json('data.path'));
    }

    #[Test]
    public function a_video_upload_is_reported_as_a_video(): void
    {
        Storage::fake(config('filesystems.uploads_disk', 'public'));
        $this->actingAsUser($this->makeUser('Me'));

        $this->post("{$this->base}/media", [
            'file' => UploadedFile::fake()->create('clip.mp4', 128, 'video/mp4'),
        ])->assertOk()->assertJsonPath('data.kind', 'video');
    }

    #[Test]
    public function a_media_file_over_the_size_cap_is_refused(): void
    {
        Storage::fake(config('filesystems.uploads_disk', 'public'));
        $this->actingAsUser($this->makeUser('Me'));

        $this->post("{$this->base}/media", [
            'file' => UploadedFile::fake()->create('clip.mp4', MediaController::MAX_KB + 1, 'video/mp4'),
        ])->assertStatus(422);
    }

    #[Test]
    public function a_moment_cannot_carry_more_than_six_media(): void
    {
        $this->actingAsUser($this->makeUser('Me'));

        $urls = array_map(fn ($i) => "https://cdn.test/{$i}.jpg", range(1, 7));

        $this->postJson("{$this->base}/posts", [
            'type'       => Post::IMAGE,
            'body'       => 'Too many',
            'media_urls' => $urls,
        ])->assertStatus(422);
    }

    /** A streamed upload names the part after a cache file that may have none. */
    #[Test]
    public function a_video_with_no_filename_extension_is_still_reported_as_a_video(): void
    {
        Storage::fake(config('filesystems.uploads_disk', 'public'));
        $this->actingAsUser($this->makeUser('Me'));

        $this->post("{$this->base}/media", [
            'file' => UploadedFile::fake()->create('upload', 128, 'video/mp4'),
        ])->assertOk()->assertJsonPath('data.kind', 'video');
    }

    /** A clip long enough to be worth posting — the old 10 MB cap refused these. */
    #[Test]
    public function a_video_of_several_tens_of_megabytes_is_accepted(): void
    {
        Storage::fake(config('filesystems.uploads_disk', 'public'));
        $this->actingAsUser($this->makeUser('Me'));

        $this->post("{$this->base}/media", [
            'file' => UploadedFile::fake()->create('clip.mp4', 60 * 1024, 'video/mp4'),
        ])->assertOk()->assertJsonPath('data.kind', 'video');
    }

    #[Test]
    public function a_file_that_is_neither_image_nor_video_is_refused(): void
    {
        Storage::fake(config('filesystems.uploads_disk', 'public'));
        $this->actingAsUser($this->makeUser('Me'));

        $this->post("{$this->base}/media", [
            'file' => UploadedFile::fake()->create('notes.pdf', 16, 'application/pdf'),
        ])->assertStatus(422);
    }

    #[Test]
    public function a_moment_can_carry_several_media_urls_as_one_video_post(): void
    {
        $this->actingAsUser($this->makeUser('Me'));

        $this->postJson("{$this->base}/posts", [
            'type'       => Post::VIDEO,
            'body'       => 'Trip',
            'media_urls' => ['https://cdn.test/a.jpg', 'https://cdn.test/b.mp4'],
        ])->assertStatus(201)
            ->assertJsonPath('data.post.type', Post::VIDEO)
            ->assertJsonCount(2, 'data.post.media_urls');
    }
}
