<?php

namespace Tests\Feature\Api;

use App\Events\Social\UserFollowed;
use App\Models\Block;
use App\Models\Follow;
use App\Models\SearchHistory;
use App\Models\User;
use App\Models\UserSanction;
use App\Models\UserVisit;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/** Epic D.3a / D.3b / D.9c acceptance criteria — search, follow, friends, blocks, visitors. */
class SocialGraphTest extends MobileTestCase
{
    // ------------------------------------------------------------------ follows

    #[Test]
    public function following_someone_moves_both_counts_immediately(): void
    {
        // D.3b — "their follower count increases immediately for both of us".
        $me = $this->actingAsUser($this->makeUser('Me'));
        $them = $this->makeUser('Them');

        $this->postJson("{$this->base}/users/{$them->uuid}/follow")
            ->assertOk()
            ->assertJsonPath('data.is_following', true)
            ->assertJsonPath('data.follower_count', 1)
            ->assertJsonPath('data.following_count', 1);

        $this->assertDatabaseHas('follows', [
            'follower_id' => $me->id, 'following_id' => $them->id,
        ]);
    }

    #[Test]
    public function following_twice_is_idempotent(): void
    {
        $this->actingAsUser($this->makeUser('Me'));
        $them = $this->makeUser('Them');

        $this->postJson("{$this->base}/users/{$them->uuid}/follow")->assertOk();
        $this->postJson("{$this->base}/users/{$them->uuid}/follow")
            ->assertOk()
            ->assertJsonPath('data.follower_count', 1);

        $this->assertSame(1, Follow::count());
    }

    #[Test]
    public function a_follow_broadcasts_to_both_sides(): void
    {
        Event::fake([UserFollowed::class]);

        $me = $this->actingAsUser($this->makeUser('Me'));
        $them = $this->makeUser('Them');

        $this->postJson("{$this->base}/users/{$them->uuid}/follow")->assertOk();

        Event::assertDispatched(UserFollowed::class, function (UserFollowed $e) use ($me, $them) {
            $channels = array_map(fn ($c) => (string) $c, $e->broadcastOn());

            return $e->followed
                && in_array("private-user.{$me->uuid}", $channels, true)
                && in_array("private-user.{$them->uuid}", $channels, true);
        });
    }

    #[Test]
    public function unfollowing_removes_the_row_and_reports_the_new_counts(): void
    {
        $me = $this->actingAsUser($this->makeUser('Me'));
        $them = $this->makeUser('Them');
        $this->follow($me, $them);

        $this->deleteJson("{$this->base}/users/{$them->uuid}/follow")
            ->assertOk()
            ->assertJsonPath('data.is_following', false)
            ->assertJsonPath('data.follower_count', 0);

        $this->assertSame(0, Follow::count());
    }

    #[Test]
    public function you_cannot_follow_yourself(): void
    {
        $me = $this->actingAsUser($this->makeUser('Me'));

        $this->postJson("{$this->base}/users/{$me->uuid}/follow")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function the_follower_list_marks_which_of_them_i_follow_back(): void
    {
        $me = $this->actingAsUser($this->makeUser('Me'));
        $mutual = $this->makeUser('Mutual');
        $oneWay = $this->makeUser('OneWay');

        $this->follow($mutual, $me);
        $this->follow($oneWay, $me);
        $this->follow($me, $mutual);

        $response = $this->getJson("{$this->base}/users/{$me->uuid}/followers")->assertOk();

        $rows = collect($response->json('data'))->keyBy('uuid');

        $this->assertTrue($rows[$mutual->uuid]['is_following']);
        $this->assertFalse($rows[$oneWay->uuid]['is_following']);
    }

    // ------------------------------------------------------------------ friends

    #[Test]
    public function a_one_way_follow_is_not_a_friendship(): void
    {
        $me = $this->actingAsUser($this->makeUser('Me'));
        $them = $this->makeUser('Them');

        $this->postJson("{$this->base}/users/{$them->uuid}/follow")->assertOk();

        $this->assertEmpty($this->getJson("{$this->base}/friends")->assertOk()->json('data'));
    }

    #[Test]
    public function following_back_makes_both_people_friends(): void
    {
        $a = $this->makeUser('A');
        $b = $this->makeUser('B');

        $this->actingAsUser($a);
        $this->postJson("{$this->base}/users/{$b->uuid}/follow")->assertOk();

        // Still nobody's friend until it is returned.
        $this->assertEmpty($this->getJson("{$this->base}/friends")->json('data'));

        $this->actingAsUser($b);
        $this->postJson("{$this->base}/users/{$a->uuid}/follow")->assertOk();

        // And now it is true from both sides at once — there is only one fact, the pair of
        // follow rows, so there is nothing that can disagree.
        $this->assertSame($a->uuid, $this->getJson("{$this->base}/friends")->json('data.0.uuid'));

        $this->actingAsUser($a);
        $this->assertSame($b->uuid, $this->getJson("{$this->base}/friends")->json('data.0.uuid'));
    }

    #[Test]
    public function unfollowing_ends_the_friendship_for_both(): void
    {
        $a = $this->makeUser('A');
        $b = $this->makeUser('B');
        $this->befriend($a, $b);

        $this->actingAsUser($a);
        $this->deleteJson("{$this->base}/users/{$b->uuid}/follow")->assertOk();

        $this->assertEmpty($this->getJson("{$this->base}/friends")->json('data'));

        $this->actingAsUser($b);
        $this->assertEmpty($this->getJson("{$this->base}/friends")->json('data'));
    }

    #[Test]
    public function the_friend_list_excludes_a_blocked_mutual(): void
    {
        $me = $this->makeUser('Me');
        $friend = $this->makeUser('Friend');
        $this->befriend($me, $friend);

        $this->actingAsUser($me);
        $this->assertCount(1, $this->getJson("{$this->base}/friends")->json('data'));

        $this->postJson("{$this->base}/users/{$friend->uuid}/block")->assertOk();

        $this->assertEmpty($this->getJson("{$this->base}/friends")->json('data'));
    }

    #[Test]
    public function the_friend_list_is_paginated_rather_than_intersected_in_memory(): void
    {
        $me = $this->actingAsUser($this->makeUser('Me'));

        foreach (range(1, 5) as $i) {
            $this->befriend($me, $this->makeUser("Friend {$i}"));
        }

        // A follower the caller does not follow back must not appear.
        $this->follow($this->makeUser('Fan'), $me);

        $response = $this->getJson("{$this->base}/friends?per_page=2")->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(5, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.last_page'));
    }

    // ------------------------------------------------------------------- blocks

    #[Test]
    public function blocking_removes_the_follow_rows_in_both_directions(): void
    {
        // D.9c — "neither of us appears in the other's follower list".
        $me = $this->actingAsUser($this->makeUser('Me'));
        $them = $this->makeUser('Them');

        $this->follow($me, $them);
        $this->follow($them, $me);

        $this->postJson("{$this->base}/users/{$them->uuid}/block", ['reason' => 'harassment'])
            ->assertOk()
            ->assertJsonPath('data.is_blocked', true);

        $this->assertSame(0, Follow::count());
        $this->assertDatabaseHas('blocks', ['blocker_id' => $me->id, 'blocked_id' => $them->id]);
    }

    #[Test]
    public function a_blocked_person_cannot_follow_back(): void
    {
        $me = $this->makeUser('Me');
        $them = $this->makeUser('Them');

        $this->actingAsUser($me);
        $this->postJson("{$this->base}/users/{$them->uuid}/block")->assertOk();

        // The block is one-directional as a row and symmetric as a rule: the person who was
        // blocked must hit it too, not only the one who placed it.
        $this->actingAsUser($them);
        $this->postJson("{$this->base}/users/{$me->uuid}/follow")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'BLOCKED');
    }

    #[Test]
    public function a_blocked_person_is_not_findable_in_search(): void
    {
        $me = $this->makeUser('Me');
        $them = $this->makeUser('Hidden');

        $this->actingAsUser($me);
        $this->postJson("{$this->base}/users/{$them->uuid}/block")->assertOk();

        $response = $this->getJson("{$this->base}/search?q=Hidden&type=users")->assertOk();

        $this->assertEmpty($response->json('data.users'));
    }

    #[Test]
    public function unblocking_takes_effect_at_once(): void
    {
        $me = $this->makeUser('Me');
        $them = $this->makeUser('Them');

        $this->actingAsUser($me);
        $this->postJson("{$this->base}/users/{$them->uuid}/block")->assertOk();
        $this->deleteJson("{$this->base}/users/{$them->uuid}/block")->assertOk();

        // The block cache has a 60-second TTL; if the unblock did not flush it, this fails.
        $this->postJson("{$this->base}/users/{$them->uuid}/follow")->assertOk();

        $this->assertSame(0, Block::count());
    }

    // ----------------------------------------------------------------- visitors

    #[Test]
    public function a_repeat_visit_bumps_the_count_rather_than_adding_a_row(): void
    {
        $visitor = $this->actingAsUser($this->makeUser('Visitor'));
        $owner = $this->makeUser('Owner');

        $this->postJson("{$this->base}/users/{$owner->uuid}/visit")
            ->assertOk()
            ->assertJsonPath('data.is_new_visitor', true);

        $this->postJson("{$this->base}/users/{$owner->uuid}/visit")
            ->assertOk()
            ->assertJsonPath('data.is_new_visitor', false);

        $this->assertSame(1, UserVisit::count());
        $this->assertSame(2, UserVisit::first()->visit_count);
    }

    #[Test]
    public function viewing_your_own_profile_is_not_a_visit(): void
    {
        $me = $this->actingAsUser($this->makeUser('Me'));

        $this->postJson("{$this->base}/users/{$me->uuid}/visit")->assertOk();

        $this->assertSame(0, UserVisit::count());
    }

    #[Test]
    public function you_cannot_read_somebody_elses_visitor_list(): void
    {
        $this->actingAsUser($this->makeUser('Me'));
        $other = $this->makeUser('Other');

        $this->getJson("{$this->base}/users/{$other->uuid}/visitors")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    // ------------------------------------------------------------------- search

    #[Test]
    public function search_finds_people_by_display_name_and_excludes_the_caller(): void
    {
        $me = $this->actingAsUser($this->makeUser('Rajesh'));
        $other = $this->makeUser('Rajesh');

        $response = $this->getJson("{$this->base}/search?q=Rajesh&type=users")->assertOk();

        $uuids = collect($response->json('data.users'))->pluck('uuid');

        $this->assertTrue($uuids->contains($other->uuid));
        $this->assertFalse($uuids->contains($me->uuid));
    }

    #[Test]
    public function a_one_character_query_returns_nothing(): void
    {
        $this->actingAsUser($this->makeUser('Me'));
        $this->makeUser('A');

        $response = $this->getJson("{$this->base}/search?q=A&type=users")->assertOk();

        $this->assertEmpty($response->json('data.users'));
    }

    #[Test]
    public function search_history_deduplicates_and_can_be_cleared(): void
    {
        $me = $this->actingAsUser($this->makeUser('Me'));

        $this->postJson("{$this->base}/search/history", ['term' => 'guftagu'])->assertStatus(201);
        $this->postJson("{$this->base}/search/history", ['term' => 'guftagu'])->assertStatus(201);
        $this->postJson("{$this->base}/search/history", ['term' => 'shayari'])->assertStatus(201);

        $this->assertSame(2, SearchHistory::where('user_id', $me->id)->count());

        $history = $this->getJson("{$this->base}/search/history")->assertOk()->json('data');
        $this->assertSame('shayari', $history[0]['term']);

        $this->deleteJson("{$this->base}/search/history/{$history[0]['uuid']}")->assertOk();
        $this->assertSame(1, SearchHistory::where('user_id', $me->id)->count());

        $this->deleteJson("{$this->base}/search/history")->assertOk();
        $this->assertSame(0, SearchHistory::where('user_id', $me->id)->count());
    }

    #[Test]
    public function history_is_trimmed_to_the_most_recent_entries(): void
    {
        $me = $this->actingAsUser($this->makeUser('Me'));

        foreach (range(1, SearchHistory::KEEP + 5) as $i) {
            $this->postJson("{$this->base}/search/history", ['term' => "term-{$i}"])->assertStatus(201);
        }

        $this->assertSame(SearchHistory::KEEP, SearchHistory::where('user_id', $me->id)->count());
        $this->assertDatabaseMissing('search_histories', ['user_id' => $me->id, 'term' => 'term-1']);
    }

    #[Test]
    public function you_cannot_delete_somebody_elses_history_entry(): void
    {
        $other = $this->actingAsUser($this->makeUser('Other'));
        $this->postJson("{$this->base}/search/history", ['term' => 'private thing'])->assertStatus(201);

        $entry = SearchHistory::where('user_id', $other->id)->firstOrFail();

        $this->actingAsUser($this->makeUser('Me'));
        $this->deleteJson("{$this->base}/search/history/{$entry->uuid}")->assertStatus(404);

        $this->assertDatabaseHas('search_histories', ['id' => $entry->id]);
    }

    // -------------------------------------------------------------------- guard

    #[Test]
    public function a_suspended_account_is_refused(): void
    {
        $me = $this->makeUser('Suspended', User::STATUS_SUSPENDED);

        // The column alone is not enough — `effectiveStatus()` treats a suspension whose
        // sanction has lapsed as active again (A.5d). The live sanction is what makes this
        // a real suspension rather than a stale column.
        UserSanction::create([
            'user_id'    => $me->id,
            'type'       => UserSanction::TEMP_BAN,
            'scope'      => 'global',
            'reason'     => 'test',
            'starts_at'  => now()->subHour(),
            'expires_at' => now()->addDay(),
            'is_active'  => true,
        ]);

        $this->actingAsUser($me->fresh());

        $this->getJson("{$this->base}/feed")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    #[Test]
    public function a_suspension_whose_sanction_has_lapsed_lets_the_user_back_in(): void
    {
        $me = $this->makeUser('Lapsed', User::STATUS_SUSPENDED);

        UserSanction::create([
            'user_id'    => $me->id,
            'type'       => UserSanction::TEMP_BAN,
            'scope'      => 'global',
            'reason'     => 'test',
            'starts_at'  => now()->subDays(2),
            'expires_at' => now()->subHour(),
            'is_active'  => true,
        ]);

        $this->actingAsUser($me->fresh());

        // Nothing rewrites `users.status` when a sanction lapses, so reading the column
        // alone would lock this account out forever.
        $this->getJson("{$this->base}/feed")->assertOk();
    }

    #[Test]
    public function the_mobile_group_rejects_an_unauthenticated_caller(): void
    {
        $this->getJson("{$this->base}/feed")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
