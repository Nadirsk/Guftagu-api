<?php

namespace Tests\Feature\Api;

use App\Domain\Moderation\ContentFilter;
use App\Events\Chat\MessageDeleted;
use App\Events\Chat\MessageSent;
use App\Models\BannedWord;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/** Epic D.4 acceptance criteria — conversations, messages, unread counts, D.9c on send. */
class ChatTest extends MobileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(ContentFilter::class)->flush();
    }

    /**
     * @return array{0: User, 1: User, 2: string} sender, recipient, conversation uuid
     */
    protected function directThread(): array
    {
        $a = $this->makeUser('Sender');
        $b = $this->makeUser('Recipient');

        // Direct messaging is gated on a mutual follow, so every DM test starts as friends.
        $this->befriend($a, $b);

        $this->actingAsUser($a);

        $uuid = $this->postJson("{$this->base}/conversations", ['user_uuid' => $b->uuid])
            ->assertStatus(201)
            ->json('data.conversation.uuid');

        return [$a, $b, $uuid];
    }

    // ------------------------------------------------------------ conversations

    #[Test]
    public function starting_a_direct_conversation_twice_reuses_the_same_thread(): void
    {
        [$a, $b, $uuid] = $this->directThread();

        $again = $this->postJson("{$this->base}/conversations", ['user_uuid' => $b->uuid])
            ->assertStatus(201)
            ->json('data.conversation.uuid');

        $this->assertSame($uuid, $again);
        $this->assertSame(1, Conversation::count());

        // And from the other side too — "message them back" must not fork the history.
        $this->actingAsUser($b);
        $fromB = $this->postJson("{$this->base}/conversations", ['user_uuid' => $a->uuid])
            ->json('data.conversation.uuid');

        $this->assertSame($uuid, $fromB);
        $this->assertSame(1, Conversation::count());
    }

    #[Test]
    public function a_direct_thread_is_not_confused_with_a_group_containing_the_same_two_people(): void
    {
        $a = $this->makeUser('A');
        $b = $this->makeUser('B');
        $c = $this->makeUser('C');
        $this->befriend($a, $b);
        $this->befriend($a, $c);

        $this->actingAsUser($a);

        $group = $this->postJson("{$this->base}/conversations", [
            'title' => 'Three of us', 'member_uuids' => [$b->uuid, $c->uuid],
        ])->assertStatus(201)->json('data.conversation.uuid');

        $direct = $this->postJson("{$this->base}/conversations", ['user_uuid' => $b->uuid])
            ->assertStatus(201)->json('data.conversation.uuid');

        $this->assertNotSame($group, $direct);
    }

    #[Test]
    public function you_cannot_open_a_conversation_you_are_not_in(): void
    {
        [, , $uuid] = $this->directThread();

        $this->actingAsUser($this->makeUser('Outsider'));

        $this->getJson("{$this->base}/conversations/{$uuid}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // --------------------------------------------------------------- messaging

    #[Test]
    public function sending_a_message_increments_only_the_recipients_unread_count(): void
    {
        [$a, $b, $uuid] = $this->directThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'Salaam'])
            ->assertStatus(201)
            ->assertJsonPath('data.message.body', 'Salaam')
            ->assertJsonPath('data.message.sender.uuid', $a->uuid);

        $conversation = Conversation::where('uuid', $uuid)->firstOrFail();

        $this->assertSame(0, ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $a->id)->value('unread_count'));

        $this->assertSame(1, ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $b->id)->value('unread_count'));
    }

    #[Test]
    public function a_message_broadcasts_to_the_thread_and_to_each_recipient(): void
    {
        Event::fake([MessageSent::class]);

        [, $b, $uuid] = $this->directThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'Hi'])->assertStatus(201);

        Event::assertDispatched(MessageSent::class, function (MessageSent $e) use ($uuid, $b) {
            $channels = array_map(fn ($c) => (string) $c, $e->broadcastOn());

            return in_array("private-conversation.{$uuid}", $channels, true)
                && in_array("private-user.{$b->uuid}", $channels, true);
        });
    }

    #[Test]
    public function the_sender_is_not_on_their_own_recipient_fan_out(): void
    {
        Event::fake([MessageSent::class]);

        [$a, , $uuid] = $this->directThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'Hi'])->assertStatus(201);

        Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => ! in_array(
            "private-user.{$a->uuid}",
            array_map(fn ($c) => (string) $c, $e->broadcastOn()),
            true,
        ));
    }

    #[Test]
    public function an_empty_message_is_refused(): void
    {
        [, , $uuid] = $this->directThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => '  '])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function a_banned_word_blocks_a_dm(): void
    {
        BannedWord::create([
            'word' => 'forbidden', 'language' => 'any', 'severity' => 'block',
            'scope' => ['dm'], 'is_active' => true,
        ]);
        app(ContentFilter::class)->flush();

        [, , $uuid] = $this->directThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'a forbidden thing'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BANNED_WORD_DETECTED');

        $this->assertSame(0, Message::count());
    }

    #[Test]
    public function a_reply_to_a_message_outside_the_thread_is_refused(): void
    {
        $a = $this->makeUser('A');
        $b = $this->makeUser('B');
        $c = $this->makeUser('C');
        $this->befriend($a, $b);
        $this->befriend($a, $c);

        $this->actingAsUser($a);

        $withB = $this->postJson("{$this->base}/conversations", ['user_uuid' => $b->uuid])
            ->json('data.conversation.uuid');
        $withC = $this->postJson("{$this->base}/conversations", ['user_uuid' => $c->uuid])
            ->json('data.conversation.uuid');

        $strayUuid = $this->postJson("{$this->base}/conversations/{$withC}/messages", ['body' => 'to C'])
            ->assertStatus(201)
            ->json('data.message.uuid');

        $this->postJson("{$this->base}/conversations/{$withB}/messages", [
            'body' => 'quoting C in B', 'reply_to_uuid' => $strayUuid,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_reply_within_the_thread_comes_back_as_a_uuid(): void
    {
        [, , $uuid] = $this->directThread();

        $target = $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'original'])
            ->json('data.message.uuid');

        // docs/03 §2.4 — the wire never carries `reply_to_id`.
        $this->postJson("{$this->base}/conversations/{$uuid}/messages", [
            'body' => 'quoting', 'reply_to_uuid' => $target,
        ])->assertStatus(201)->assertJsonPath('data.message.reply_to', $target);

        $this->assertSame($target, $this->getJson("{$this->base}/conversations/{$uuid}/messages")
            ->json('data.0.reply_to'));
    }

    // ------------------------------------------------------- mutual-follow gate

    #[Test]
    public function you_cannot_start_a_dm_with_someone_who_is_not_a_friend(): void
    {
        $a = $this->makeUser('A');
        $b = $this->makeUser('B');

        $this->actingAsUser($a);

        // Strangers.
        $this->postJson("{$this->base}/conversations", ['user_uuid' => $b->uuid])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NOT_FRIENDS');

        // One-way follow is still not enough — they have to follow back.
        $this->follow($a, $b);
        $this->postJson("{$this->base}/conversations", ['user_uuid' => $b->uuid])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NOT_FRIENDS');

        $this->follow($b, $a);
        $this->postJson("{$this->base}/conversations", ['user_uuid' => $b->uuid])->assertStatus(201);
    }

    #[Test]
    public function unfollowing_closes_an_existing_thread_to_new_messages(): void
    {
        [$a, $b, $uuid] = $this->directThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'while friends'])
            ->assertStatus(201);

        // The thread already exists, so the gate has to be on send too, not only on create.
        $this->deleteJson("{$this->base}/users/{$b->uuid}/follow")->assertOk();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'after'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NOT_FRIENDS');

        // And from the other side, who never unfollowed anyone.
        $this->actingAsUser($b);
        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'hello?'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NOT_FRIENDS');
    }

    #[Test]
    public function an_unfriended_thread_keeps_its_history(): void
    {
        [$a, $b, $uuid] = $this->directThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'said before'])
            ->assertStatus(201);
        $this->deleteJson("{$this->base}/users/{$b->uuid}/follow")->assertOk();

        $messages = $this->getJson("{$this->base}/conversations/{$uuid}/messages")->assertOk()->json('data');

        $this->assertCount(1, $messages);
        $this->assertSame('said before', $messages[0]['body']);
    }

    #[Test]
    public function a_group_only_accepts_members_the_creator_is_friends_with(): void
    {
        $owner = $this->makeUser('Owner');
        $friend = $this->makeUser('Friend');
        $stranger = $this->makeUser('Stranger');

        $this->befriend($owner, $friend);

        $this->actingAsUser($owner);

        $response = $this->postJson("{$this->base}/conversations", [
            'title' => 'Mehfil', 'member_uuids' => [$friend->uuid, $stranger->uuid],
        ])->assertStatus(201);

        $uuids = collect($response->json('data.conversation.participants'))->pluck('uuid');

        $this->assertTrue($uuids->contains($friend->uuid));
        $this->assertFalse($uuids->contains($stranger->uuid));
    }

    #[Test]
    public function group_members_can_talk_without_being_friends_with_each_other(): void
    {
        // Requiring every pair in a group to be mutual followers would make groups
        // impossible, and a group is the one place people are expected to meet strangers.
        $owner = $this->makeUser('Owner');
        $x = $this->makeUser('X');
        $y = $this->makeUser('Y');

        $this->befriend($owner, $x);
        $this->befriend($owner, $y);

        $this->actingAsUser($owner);
        $uuid = $this->postJson("{$this->base}/conversations", [
            'title' => 'Mehfil', 'member_uuids' => [$x->uuid, $y->uuid],
        ])->assertStatus(201)->json('data.conversation.uuid');

        // X and Y have never followed each other.
        $this->actingAsUser($x);
        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'salaam'])
            ->assertStatus(201);

        $this->actingAsUser($y);
        $this->assertSame('salaam', $this->getJson("{$this->base}/conversations/{$uuid}/messages")
            ->json('data.0.body'));
    }

    // ------------------------------------------------------------------- blocks

    #[Test]
    public function a_blocked_user_cannot_send_a_dm_in_either_direction(): void
    {
        // D.9c — "they cannot DM me".
        [$a, $b, $uuid] = $this->directThread();

        $this->actingAsUser($a);
        $this->postJson("{$this->base}/users/{$b->uuid}/block")->assertOk();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'still here?'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'BLOCKED');

        // And the person who was blocked, who never placed a block of their own.
        $this->actingAsUser($b);
        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'hello?'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'BLOCKED');
    }

    #[Test]
    public function blocking_keeps_the_history(): void
    {
        [$a, $b, $uuid] = $this->directThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'evidence'])->assertStatus(201);
        $this->postJson("{$this->base}/users/{$b->uuid}/block")->assertOk();

        $messages = $this->getJson("{$this->base}/conversations/{$uuid}/messages")->assertOk()->json('data');

        $this->assertCount(1, $messages);
        $this->assertSame('evidence', $messages[0]['body']);
    }

    // ---------------------------------------------------------------- read/mute

    #[Test]
    public function marking_read_clears_the_unread_count_and_is_repeatable(): void
    {
        [$a, $b, $uuid] = $this->directThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'one'])->assertStatus(201);
        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'two'])->assertStatus(201);

        $this->actingAsUser($b);
        $this->postJson("{$this->base}/conversations/{$uuid}/read")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        // A second call must be a no-op, not an error.
        $this->postJson("{$this->base}/conversations/{$uuid}/read")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    #[Test]
    public function a_muted_thread_stops_producing_notifications_but_still_delivers(): void
    {
        [$a, $b, $uuid] = $this->directThread();

        $this->actingAsUser($b);
        $this->postJson("{$this->base}/conversations/{$uuid}/mute", ['muted' => true])
            ->assertOk()
            ->assertJsonPath('data.is_muted', true);

        $this->actingAsUser($a);
        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'quiet'])->assertStatus(201);

        // Mute silences the push, not the message: the unread badge still moves and the
        // socket frame still goes out. Muting a thread is not leaving it.
        $this->assertDatabaseMissing('notifications', ['user_id' => $b->id, 'type' => 'message.new']);

        $this->assertSame(1, ConversationParticipant::query()
            ->where('conversation_id', Conversation::where('uuid', $uuid)->value('id'))
            ->where('user_id', $b->id)
            ->value('unread_count'));
    }

    #[Test]
    public function the_dm_list_shows_the_last_message_and_the_callers_own_unread_count(): void
    {
        [$a, $b, $uuid] = $this->directThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'latest'])->assertStatus(201);

        $this->actingAsUser($b);
        $rows = $this->getJson("{$this->base}/conversations")->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('latest', $rows[0]['last_message']['body']);
        $this->assertSame(1, $rows[0]['unread_count']);
        $this->assertSame($a->uuid, $rows[0]['participants'][0]['uuid']);

        $this->actingAsUser($a);
        $this->assertSame(0, $this->getJson("{$this->base}/conversations")->json('data.0.unread_count'));
    }

    // ------------------------------------------------------------------ deletes

    #[Test]
    public function delete_for_me_hides_the_message_from_one_side_only(): void
    {
        [$a, $b, $uuid] = $this->directThread();

        $messageUuid = $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'oops'])
            ->json('data.message.uuid');

        $this->deleteJson("{$this->base}/conversations/{$uuid}/messages/{$messageUuid}")->assertOk();

        $this->assertEmpty($this->getJson("{$this->base}/conversations/{$uuid}/messages")->json('data'));

        $this->actingAsUser($b);
        $this->assertCount(1, $this->getJson("{$this->base}/conversations/{$uuid}/messages")->json('data'));
    }

    #[Test]
    public function delete_for_everyone_is_the_senders_privilege_and_broadcasts(): void
    {
        Event::fake([MessageDeleted::class]);

        [, $b, $uuid] = $this->directThread();

        $messageUuid = $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'oops'])
            ->json('data.message.uuid');

        $this->actingAsUser($b);
        $this->deleteJson("{$this->base}/conversations/{$uuid}/messages/{$messageUuid}?for_everyone=1")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->actingAsUser(Message::where('uuid', $messageUuid)->firstOrFail()->sender);
        $this->deleteJson("{$this->base}/conversations/{$uuid}/messages/{$messageUuid}?for_everyone=1")->assertOk();

        Event::assertDispatched(MessageDeleted::class);

        $this->actingAsUser($b);
        $rows = $this->getJson("{$this->base}/conversations/{$uuid}/messages")->json('data');
        $this->assertTrue($rows[0]['is_deleted']);
        $this->assertNull($rows[0]['body']);
    }

    #[Test]
    public function messages_page_by_cursor_newest_first(): void
    {
        [, , $uuid] = $this->directThread();

        foreach (range(1, 5) as $i) {
            $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => "m{$i}"])->assertStatus(201);
        }

        $first = $this->getJson("{$this->base}/conversations/{$uuid}/messages?limit=2")->assertOk();

        $this->assertSame('m5', $first->json('data.0.body'));
        $this->assertTrue($first->json('meta.has_more'));

        $second = $this->getJson(
            "{$this->base}/conversations/{$uuid}/messages?limit=2&cursor=".$first->json('meta.next_cursor')
        )->assertOk();

        $this->assertSame('m3', $second->json('data.0.body'));
    }
}
