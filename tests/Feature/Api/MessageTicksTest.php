<?php

namespace Tests\Feature\Api;

use App\Events\Chat\MessageStatusUpdated;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * WhatsApp-style delivery receipts — ✓ sent, ✓✓ delivered, ✓✓ blue read (epic D.4).
 *
 * The rule being tested throughout: **a group message is only delivered/read when every
 * remaining participant has reached it.** One straggler holds the whole thread at the
 * lower state, which is what makes a group tick mean anything.
 */
class MessageTicksTest extends MobileTestCase
{
    /** @return array{0: User, 1: User, 2: string} */
    protected function directThread(): array
    {
        $a = $this->makeUser('Sender');
        $b = $this->makeUser('Recipient');
        $this->befriend($a, $b);

        $this->actingAsUser($a);

        $uuid = $this->postJson("{$this->base}/conversations", ['user_uuid' => $b->uuid])
            ->assertStatus(201)
            ->json('data.conversation.uuid');

        return [$a, $b, $uuid];
    }

    protected function statusOfLatest(string $conversationUuid): ?string
    {
        return $this->getJson("{$this->base}/conversations/{$conversationUuid}/messages")
            ->assertOk()
            ->json('data.0.status');
    }

    // -------------------------------------------------------------- one to one

    #[Test]
    public function a_new_message_starts_at_sent(): void
    {
        [$a, , $uuid] = $this->directThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'hi'])
            ->assertStatus(201)
            ->assertJsonPath('data.message.status', 'sent');

        $this->assertSame('sent', $this->statusOfLatest($uuid));
    }

    #[Test]
    public function the_full_tick_lifecycle_runs_sent_then_delivered_then_read(): void
    {
        [$a, $b, $uuid] = $this->directThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'hi'])->assertStatus(201);
        $this->assertSame('sent', $this->statusOfLatest($uuid));

        // B's device acks receipt without opening the thread — second tick.
        $this->actingAsUser($b);
        $this->postJson("{$this->base}/conversations/{$uuid}/delivered")->assertOk();

        $this->actingAsUser($a);
        $this->assertSame('delivered', $this->statusOfLatest($uuid));

        // B opens the thread — blue.
        $this->actingAsUser($b);
        $this->postJson("{$this->base}/conversations/{$uuid}/read")->assertOk();

        $this->actingAsUser($a);
        $this->assertSame('read', $this->statusOfLatest($uuid));
    }

    #[Test]
    public function reading_implies_delivery_even_if_the_ack_never_arrived(): void
    {
        // A message that is read but not delivered has no tick to render and no way to have
        // happened, so `read` has to carry `delivered` with it.
        [$a, $b, $uuid] = $this->directThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'hi'])->assertStatus(201);

        $this->actingAsUser($b);
        $this->postJson("{$this->base}/conversations/{$uuid}/read")->assertOk();

        $mine = ConversationParticipant::query()
            ->where('conversation_id', Conversation::where('uuid', $uuid)->value('id'))
            ->where('user_id', $b->id)
            ->firstOrFail();

        $this->assertNotNull($mine->last_delivered_message_id);
        $this->assertSame($mine->last_read_message_id, $mine->last_delivered_message_id);
    }

    #[Test]
    public function a_late_ack_cannot_drag_a_mark_backwards(): void
    {
        [$a, $b, $uuid] = $this->directThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'one'])->assertStatus(201);
        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'two'])->assertStatus(201);

        $this->actingAsUser($b);
        $this->postJson("{$this->base}/conversations/{$uuid}/read")->assertOk();

        $participant = ConversationParticipant::query()
            ->where('conversation_id', Conversation::where('uuid', $uuid)->value('id'))
            ->where('user_id', $b->id)
            ->firstOrFail();

        $readMark = $participant->last_read_message_id;

        // A delivered ack arriving after the read one must not pull the delivery mark back
        // to an older message — out-of-order acks are the normal case on a flaky network.
        $this->postJson("{$this->base}/conversations/{$uuid}/delivered")->assertOk();

        $this->assertSame($readMark, $participant->fresh()->last_delivered_message_id);

        $this->actingAsUser($a);
        $this->assertSame('read', $this->statusOfLatest($uuid));
    }

    #[Test]
    public function only_your_own_messages_carry_a_tick(): void
    {
        [$a, $b, $uuid] = $this->directThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'from A'])->assertStatus(201);

        // B sees A's message. It is not B's to tick.
        $this->actingAsUser($b);
        $this->assertNull($this->statusOfLatest($uuid));
    }

    #[Test]
    public function a_status_change_broadcasts_the_thread_watermarks(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        [, $b, $uuid] = $this->directThread();

        $sent = $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'hi'])
            ->json('data.message.uuid');

        $this->actingAsUser($b);
        $this->postJson("{$this->base}/conversations/{$uuid}/read")->assertOk();

        Event::assertDispatched(MessageStatusUpdated::class, function (MessageStatusUpdated $e) use ($uuid, $b, $sent) {
            $payload = $e->broadcastWith();

            return $e->broadcastAs() === 'conversation.read'
                && (string) $e->broadcastOn()[0] === "private-conversation.{$uuid}"
                && $payload['actor']['uuid'] === $b->uuid
                && $payload['up_to_message_uuid'] === $sent
                // The aggregate is what the other side repaints against.
                && $payload['read_up_to'] === $sent
                && $payload['delivered_up_to'] === $sent;
        });
    }

    #[Test]
    public function an_ack_that_moves_nothing_broadcasts_nothing(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        [, $b, $uuid] = $this->directThread();

        // An empty thread has no message to reach, so there is no tick to announce.
        $this->actingAsUser($b);
        $this->postJson("{$this->base}/conversations/{$uuid}/delivered")->assertOk();
        $this->postJson("{$this->base}/conversations/{$uuid}/read")->assertOk();

        Event::assertNotDispatched(MessageStatusUpdated::class);
    }

    // ------------------------------------------------------------------- group

    /** @return array{0: User, 1: User, 2: User, 3: string} */
    protected function groupThread(): array
    {
        $owner = $this->makeUser('Owner');
        $x = $this->makeUser('Member X');
        $y = $this->makeUser('Member Y');
        $this->befriend($owner, $x);
        $this->befriend($owner, $y);

        $this->actingAsUser($owner);

        $uuid = $this->postJson("{$this->base}/conversations", [
            'title' => 'Mehfil', 'member_uuids' => [$x->uuid, $y->uuid],
        ])->assertStatus(201)->json('data.conversation.uuid');

        return [$owner, $x, $y, $uuid];
    }

    #[Test]
    public function a_group_message_needs_every_member_before_it_turns_grey(): void
    {
        [$owner, $x, $y, $uuid] = $this->groupThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'salaam'])->assertStatus(201);

        $this->actingAsUser($x);
        $this->postJson("{$this->base}/conversations/{$uuid}/delivered")->assertOk();

        // One of two has it. Still one tick.
        $this->actingAsUser($owner);
        $this->assertSame('sent', $this->statusOfLatest($uuid));

        $this->actingAsUser($y);
        $this->postJson("{$this->base}/conversations/{$uuid}/delivered")->assertOk();

        $this->actingAsUser($owner);
        $this->assertSame('delivered', $this->statusOfLatest($uuid));
    }

    #[Test]
    public function one_unread_member_holds_the_whole_group_at_grey(): void
    {
        [$owner, $x, $y, $uuid] = $this->groupThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'salaam'])->assertStatus(201);

        $this->actingAsUser($x);
        $this->postJson("{$this->base}/conversations/{$uuid}/read")->assertOk();

        $this->actingAsUser($y);
        $this->postJson("{$this->base}/conversations/{$uuid}/delivered")->assertOk();

        // X read it, Y only received it. Grey, not blue.
        $this->actingAsUser($owner);
        $this->assertSame('delivered', $this->statusOfLatest($uuid));

        $this->actingAsUser($y);
        $this->postJson("{$this->base}/conversations/{$uuid}/read")->assertOk();

        $this->actingAsUser($owner);
        $this->assertSame('read', $this->statusOfLatest($uuid));
    }

    #[Test]
    public function a_member_who_leaves_stops_holding_the_ticks_back(): void
    {
        [$owner, $x, $y, $uuid] = $this->groupThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'salaam'])->assertStatus(201);

        $this->actingAsUser($x);
        $this->postJson("{$this->base}/conversations/{$uuid}/read")->assertOk();

        $this->actingAsUser($owner);
        $this->assertSame('sent', $this->statusOfLatest($uuid));

        // Y never acked and then left. Their stale mark must not freeze the thread forever.
        $this->actingAsUser($y);
        $this->postJson("{$this->base}/conversations/{$uuid}/leave")->assertOk();

        $this->actingAsUser($owner);
        $this->assertSame('read', $this->statusOfLatest($uuid));
    }

    #[Test]
    public function an_older_message_stays_read_once_a_later_one_is_read(): void
    {
        // The mark is a watermark, not a per-message flag: reaching #5 means #1 to #4 are
        // read too, without five rows to say so.
        [$a, $b, $uuid] = $this->directThread();

        foreach (range(1, 5) as $i) {
            $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => "m{$i}"])->assertStatus(201);
        }

        $this->actingAsUser($b);
        $this->postJson("{$this->base}/conversations/{$uuid}/read")->assertOk();

        $this->actingAsUser($a);
        $statuses = collect($this->getJson("{$this->base}/conversations/{$uuid}/messages")->json('data'))
            ->pluck('status')
            ->unique()
            ->all();

        $this->assertSame(['read'], $statuses);
    }

    // ------------------------------------------------------------- resume sweep

    #[Test]
    public function the_resume_sweep_acknowledges_every_thread_at_once(): void
    {
        $me = $this->makeUser('Me');
        $one = $this->makeUser('One');
        $two = $this->makeUser('Two');

        $threads = [];

        foreach ([$one, $two] as $peer) {
            $this->befriend($me, $peer);
            $this->actingAsUser($peer);
            $uuid = $this->postJson("{$this->base}/conversations", ['user_uuid' => $me->uuid])
                ->assertStatus(201)->json('data.conversation.uuid');
            $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'while you were away'])
                ->assertStatus(201);
            $threads[] = $uuid;
        }

        $this->actingAsUser($me);
        $this->postJson("{$this->base}/messages/delivered")
            ->assertOk()
            ->assertJsonPath('data.threads_updated', 2);

        foreach ($threads as $i => $uuid) {
            $this->actingAsUser($i === 0 ? $one : $two);
            $this->assertSame('delivered', $this->statusOfLatest($uuid));
        }

        // Running it again moves nothing — the sweep fires on every resume.
        $this->actingAsUser($me);
        $this->postJson("{$this->base}/messages/delivered")
            ->assertOk()
            ->assertJsonPath('data.threads_updated', 0);
    }

    #[Test]
    public function the_sweep_ignores_threads_the_caller_has_left(): void
    {
        [$owner, $x, , $uuid] = $this->groupThread();

        $this->postJson("{$this->base}/conversations/{$uuid}/messages", ['body' => 'salaam'])->assertStatus(201);

        $this->actingAsUser($x);
        $this->postJson("{$this->base}/conversations/{$uuid}/leave")->assertOk();

        $this->postJson("{$this->base}/messages/delivered")
            ->assertOk()
            ->assertJsonPath('data.threads_updated', 0);
    }

    #[Test]
    public function a_non_participant_cannot_ack_a_thread(): void
    {
        [, , $uuid] = $this->directThread();

        $this->actingAsUser($this->makeUser('Outsider'));

        $this->postJson("{$this->base}/conversations/{$uuid}/delivered")->assertStatus(404);
        $this->postJson("{$this->base}/conversations/{$uuid}/read")->assertStatus(404);
    }
}
