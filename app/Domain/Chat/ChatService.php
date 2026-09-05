<?php

namespace App\Domain\Chat;

use App\Domain\Moderation\ContentFilter;
use App\Domain\Social\SocialException;
use App\Domain\Social\SocialService;
use App\Events\Chat\MessageDeleted;
use App\Events\Chat\MessageSent;
use App\Events\Chat\MessageStatusUpdated;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GFT-235 – GFT-237 — direct and group messaging (epic D.4), including D.9c's block rule
 * on send.
 *
 * Four decisions:
 *
 *  1. **A direct conversation between two people is found, not created twice.** Two clients
 *     tapping "message" at the same moment must land in one thread; the lookup runs inside
 *     a transaction and matches on the participant pair, not on a title.
 *
 *  2. **`unread_count` is incremented per recipient at send time.** The alternative — count
 *     unread rows when the DM list is drawn — is a subquery per thread on the busiest
 *     screen in the app.
 *
 *  3. **The block check is on send, both directions.** D.9c says a blocked user "cannot DM
 *     me"; if only the blocker's direction were checked, the person who was blocked could
 *     still write.
 *
 *  4. **A blocked pair keeps its history.** The thread is not deleted, only closed to new
 *     messages. Deleting the record of what was said is the opposite of what someone who
 *     blocks a harasser needs.
 */
class ChatService
{
    public function __construct(
        protected ContentFilter $filter,
        protected SocialService $social,
    ) {
    }

    // ----------------------------------------------------------- conversations

    /**
     * Find or start a direct thread between two people.
     *
     * @throws SocialException
     */
    public function directWith(User $actor, User $other): Conversation
    {
        if ($actor->id === $other->id) {
            throw new SocialException('VALIDATION_ERROR', 'You cannot start a conversation with yourself.', 422);
        }

        if (Block::existsBetween($actor->id, $other->id)) {
            throw SocialException::blocked();
        }

        // A DM needs a mutual follow. Checked here *and* on every send: the thread can
        // outlive the friendship, and an unfollow has to close it rather than leave a door
        // open for whoever opened the conversation first.
        if (! $this->social->areFriends($actor->id, $other->id)) {
            throw SocialException::notFriends();
        }

        return DB::transaction(function () use ($actor, $other) {
            $existing = $this->findDirect($actor->id, $other->id);

            if ($existing !== null) {
                return $existing;
            }

            $conversation = Conversation::create([
                'type'       => Conversation::DIRECT,
                'created_by' => $actor->id,
            ]);

            foreach ([$actor->id, $other->id] as $userId) {
                ConversationParticipant::create([
                    'conversation_id' => $conversation->id,
                    'user_id'         => $userId,
                    'role'            => 'member',
                    'joined_at'       => now(),
                ]);
            }

            return $conversation;
        });
    }

    /**
     * A direct thread is identified by its **exact** pair of participants.
     *
     * "Contains both" is not enough — a group that happens to include the two of them would
     * match, and the next DM would land in the group. The third clause is what makes it
     * exact: no participant outside the pair.
     */
    protected function findDirect(int $a, int $b): ?Conversation
    {
        return Conversation::query()
            ->where('type', Conversation::DIRECT)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $a))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $b))
            ->whereDoesntHave('participants', fn ($q) => $q->whereNotIn('user_id', [$a, $b]))
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<int, User>  $members
     *
     * @throws SocialException
     */
    public function createGroup(User $actor, string $title, array $members): Conversation
    {
        if ($members === []) {
            throw new SocialException('VALIDATION_ERROR', 'A group needs at least one other member.', 422);
        }

        return DB::transaction(function () use ($actor, $title, $members) {
            $conversation = Conversation::create([
                'type'       => Conversation::GROUP,
                'title'      => $title,
                'created_by' => $actor->id,
            ]);

            ConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id'         => $actor->id,
                'role'            => 'owner',
                'joined_at'       => now(),
            ]);

            foreach ($members as $member) {
                // You may only pull your own friends into a group. Once they are in, members
                // talk to each other freely — requiring every pair in a group to be mutual
                // followers would make groups impossible, and a group is the one place
                // people are expected to meet strangers.
                if ($member->id === $actor->id
                    || Block::existsBetween($actor->id, $member->id)
                    || ! $this->social->areFriends($actor->id, $member->id)) {
                    continue;
                }

                ConversationParticipant::firstOrCreate(
                    ['conversation_id' => $conversation->id, 'user_id' => $member->id],
                    ['role' => 'member', 'joined_at' => now()],
                );
            }

            return $conversation;
        });
    }

    /**
     * The DM list, ordered by the caller's own most recent activity.
     *
     * @return Collection<int, ConversationParticipant>
     */
    public function conversations(User $user, int $limit): Collection
    {
        return ConversationParticipant::query()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->with([
                'conversation',
                'conversation.activeParticipants.user.profile:id,user_id,display_name,avatar_url',
            ])
            ->join('conversations', 'conversations.id', '=', 'conversation_participants.conversation_id')
            ->where('conversations.is_active', true)
            ->orderByDesc('conversations.last_message_at')
            ->orderByDesc('conversations.id')
            ->select('conversation_participants.*')
            ->limit($limit)
            ->get();
    }

    /**
     * The last message in each of a set of threads, in one query.
     *
     * @param  array<int, int>  $conversationIds
     * @return array<int, Message> keyed by conversation id
     */
    public function lastMessages(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        // The max id per conversation, then the rows for those ids. Two small queries beats
        // a correlated subquery per row, and beats loading every message to pick the last.
        $ids = Message::query()
            ->whereIn('conversation_id', $conversationIds)
            ->groupBy('conversation_id')
            ->selectRaw('MAX(id) as id')
            ->pluck('id');

        return Message::query()
            ->whereIn('id', $ids)
            ->with(['sender.profile:id,user_id,display_name,avatar_url', 'replyTo:id,uuid'])
            ->get()
            ->keyBy('conversation_id')
            ->all();
    }

    // --------------------------------------------------------------- messaging

    /**
     * Send a message.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ChatException|SocialException
     */
    public function send(Conversation $conversation, User $sender, array $data): Message
    {
        $mine = $this->participantOrFail($conversation, $sender);

        $recipients = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->where('user_id', '!=', $sender->id)
            ->get();

        // D.9c, plus the mutual-follow rule. On a direct thread either one closes it
        // outright; in a group a block only removes that person from the fan-out, since one
        // block should not silence the room.
        if ($conversation->type === Conversation::DIRECT) {
            foreach ($recipients as $recipient) {
                if (Block::existsBetween($sender->id, $recipient->user_id)) {
                    throw SocialException::blocked();
                }

                // Re-checked on every send, not just when the thread was opened: an
                // unfollow has to stop the conversation, and the thread already exists.
                if (! $this->social->areFriends($sender->id, $recipient->user_id)) {
                    throw SocialException::notFriends();
                }
            }
        } else {
            $recipients = $recipients->reject(
                fn (ConversationParticipant $p) => Block::existsBetween($sender->id, $p->user_id)
            );
        }

        $body = trim((string) ($data['body'] ?? ''));
        $type = $data['type'] ?? Message::TEXT;
        $mediaUrl = $data['media_url'] ?? null;

        if ($body === '' && $mediaUrl === null) {
            throw new ChatException('VALIDATION_ERROR', 'An empty message is not a message.', 422);
        }

        if ($body !== '') {
            $result = $this->filter->checkAndFlag($body, 'dm', $sender->id);

            if ($result->blocked()) {
                throw new ChatException('BANNED_WORD_DETECTED', 'That message contains words that are not allowed.', 422);
            }

            $body = $result->filtered;
        }

        // A reply must point at a message in *this* thread. Unchecked, `reply_to` is a read
        // primitive: quote any message in the database and the client renders it.
        $replyTo = null;

        if (! empty($data['reply_to_uuid'])) {
            $replyTo = Message::where('uuid', $data['reply_to_uuid'])
                ->where('conversation_id', $conversation->id)
                ->first();

            if ($replyTo === null) {
                throw new ChatException('VALIDATION_ERROR', 'That message is not in this conversation.', 422);
            }
        }

        $message = DB::transaction(function () use ($conversation, $sender, $mine, $recipients, $body, $type, $mediaUrl, $replyTo, $data) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id'       => $sender->id,
                'type'            => $type,
                'body'            => $body === '' ? null : $body,
                'media_url'       => $mediaUrl,
                'media_meta'      => $data['media_meta'] ?? null,
                'reply_to_id'     => $replyTo?->id,
            ]);

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            // The sender has both received and read their own message by definition. Without
            // moving their own marks, the aggregate would treat them as a straggler and no
            // message they sent could ever go blue.
            $mine->forceFill([
                'last_delivered_message_id' => $message->id,
                'delivered_at'              => now(),
                'last_read_message_id'      => $message->id,
                'read_at'                   => now(),
                'unread_count'              => 0,
            ])->save();

            ConversationParticipant::query()
                ->whereIn('id', $recipients->pluck('id'))
                ->increment('unread_count');

            return $message;
        });

        $message->setRelation('sender', $sender);
        $message->setRelation('conversation', $conversation);
        $message->setRelation('replyTo', $replyTo);

        foreach ($recipients as $recipient) {
            if ($recipient->is_muted) {
                continue;
            }

            $this->notify($recipient->user_id, $conversation, $sender, $body);
        }

        MessageSent::dispatch(
            $conversation,
            $message,
            $this->uuidsFor($recipients->pluck('user_id')->all()),
        );

        return $message;
    }

    /**
     * A page of messages, newest first.
     *
     * @return array{items: Collection<int, Message>, next_cursor: ?int}
     *
     * @throws ChatException
     */
    public function messages(Conversation $conversation, User $viewer, ?int $beforeId, int $limit): array
    {
        $this->participantOrFail($conversation, $viewer);

        $query = Message::query()
            ->where('conversation_id', $conversation->id)
            ->with(['sender.profile:id,user_id,display_name,avatar_url', 'replyTo:id,uuid'])
            // "Delete for me" — MySQL's JSON_CONTAINS over the array of user ids. The column
            // is null for the overwhelming majority of rows, hence the null guard first.
            ->where(fn ($q) => $q
                ->whereNull('deleted_for')
                ->orWhereRaw('NOT JSON_CONTAINS(deleted_for, ?)', [json_encode($viewer->id)]));

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $items = $hasMore ? $rows->take($limit) : $rows;

        return [
            'items'       => $items,
            'next_cursor' => $hasMore ? $items->last()?->id : null,
        ];
    }

    // ------------------------------------------------------- delivery receipts

    /**
     * Second tick — the recipient's device has the messages.
     *
     * Called when the app receives a `message.new` frame, and again on resume, so it must be
     * idempotent and must never move the mark backwards: acks arrive out of order, and a
     * late one for message 40 landing after a sweep already acked 50 would otherwise turn
     * the sender's ticks back to grey.
     *
     * @throws ChatException
     */
    public function markDelivered(Conversation $conversation, User $viewer): ConversationParticipant
    {
        $mine = $this->participantOrFail($conversation, $viewer);

        $last = $this->lastMessage($conversation);

        if ($mine->advance('last_delivered_message_id', $last?->id, 'delivered_at')) {
            $this->broadcastStatus($conversation, $viewer, MessageStatusUpdated::DELIVERED, $last?->uuid);
        }

        return $mine->refresh();
    }

    /**
     * The app-resume sweep: acknowledge every thread at once.
     *
     * A phone that was offline comes back to twenty threads with unread messages, and
     * twenty round trips to set twenty ticks is a bad first second in the app. One query
     * finds the threads that are actually behind; only those are touched and only those
     * broadcast.
     *
     * @return int how many threads moved
     */
    public function markAllDelivered(User $viewer): int
    {
        $rows = ConversationParticipant::query()
            ->where('user_id', $viewer->id)
            ->whereNull('left_at')
            ->with('conversation')
            ->get();

        $moved = 0;

        foreach ($rows as $mine) {
            $conversation = $mine->conversation;

            if ($conversation === null) {
                continue;
            }

            $last = $this->lastMessage($conversation);

            if ($mine->advance('last_delivered_message_id', $last?->id, 'delivered_at')) {
                $this->broadcastStatus($conversation, $viewer, MessageStatusUpdated::DELIVERED, $last?->uuid);
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * Blue tick — the thread was opened.
     *
     * Reading also implies delivery. Anything else produces a message that is read but not
     * delivered, which the tick logic has no way to render and no way to have happened.
     *
     * @throws ChatException
     */
    public function markRead(Conversation $conversation, User $viewer): ConversationParticipant
    {
        $mine = $this->participantOrFail($conversation, $viewer);

        $last = $this->lastMessage($conversation);

        $movedRead = DB::transaction(function () use ($mine, $last) {
            $mine->advance('last_delivered_message_id', $last?->id, 'delivered_at');

            $moved = $mine->advance('last_read_message_id', $last?->id, 'read_at');

            // The badge clears whether or not the mark moved: an "open the thread" with
            // nothing new in it should still leave zero unread.
            if ($mine->unread_count !== 0) {
                $mine->forceFill(['unread_count' => 0])->save();
            }

            return $moved;
        });

        if ($movedRead) {
            $this->broadcastStatus($conversation, $viewer, MessageStatusUpdated::READ, $last?->uuid);
        }

        return $mine->refresh();
    }

    /**
     * The thread's tick watermarks, from `$viewer`'s point of view.
     *
     * "My message is read" means **everyone else still in the thread** has reached it, so
     * the aggregate is a MIN over the other active participants — one straggler holds the
     * whole thread at grey, which is exactly what WhatsApp does in a group.
     *
     * A participant who has never acked has a null mark, which `COALESCE` turns into 0: they
     * have reached nothing, so nothing is delivered to everyone. People who have left are
     * excluded — their stale marks must not hold the remaining members' ticks back forever.
     *
     * A thread with no other active participant (a group everyone left) yields `PHP_INT_MAX`
     * rather than 0: with nobody left to wait for, there is nobody holding it back.
     *
     * @return array{delivered_up_to: int, read_up_to: int}
     *
     * @throws ChatException
     */
    public function statusMarks(Conversation $conversation, User $viewer): array
    {
        $row = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->where('user_id', '!=', $viewer->id)
            ->selectRaw('COUNT(*) as others')
            ->selectRaw('MIN(COALESCE(last_delivered_message_id, 0)) as delivered_up_to')
            ->selectRaw('MIN(COALESCE(last_read_message_id, 0)) as read_up_to')
            ->first();

        if ($row === null || (int) $row->others === 0) {
            return ['delivered_up_to' => PHP_INT_MAX, 'read_up_to' => PHP_INT_MAX];
        }

        return [
            'delivered_up_to' => (int) $row->delivered_up_to,
            'read_up_to'      => (int) $row->read_up_to,
        ];
    }

    /** The newest message in a thread, or null when nothing has been said yet. */
    protected function lastMessage(Conversation $conversation): ?Message
    {
        return Message::where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->first(['id', 'uuid']);
    }

    /**
     * Publish a tick change, carrying the thread aggregate so the other side can repaint
     * without holding everybody's marks itself.
     */
    protected function broadcastStatus(Conversation $conversation, User $actor, string $status, ?string $upToUuid): void
    {
        // The aggregate every *other* participant will apply. Computed from the actor's
        // counterpart view — for a direct thread that is simply the actor's own marks, which
        // is why this is not `statusMarks($conversation, $actor)`.
        $marks = $this->aggregateForSenders($conversation, $actor);

        MessageStatusUpdated::dispatch(
            $conversation->uuid,
            $actor,
            $status,
            $upToUuid,
            $this->uuidForMessageId($marks['delivered_up_to']),
            $this->uuidForMessageId($marks['read_up_to']),
        );
    }

    /**
     * The watermarks as seen by whoever is *waiting* on ticks in this thread.
     *
     * A frame goes out to everyone, and each of them wants "how far has everyone but me
     * got". Computing that per recipient would be one query per participant. Instead this
     * takes the MIN across **all** active participants, which is the strictest of those
     * per-recipient answers and therefore safe for all of them: never claims a message is
     * read when somebody has not read it. The actor's own row is included, and since the
     * actor has just moved forward it is rarely the minimum anyway.
     *
     * @return array{delivered_up_to: int, read_up_to: int}
     */
    protected function aggregateForSenders(Conversation $conversation, User $actor): array
    {
        $row = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->selectRaw('MIN(COALESCE(last_delivered_message_id, 0)) as delivered_up_to')
            ->selectRaw('MIN(COALESCE(last_read_message_id, 0)) as read_up_to')
            ->first();

        return [
            'delivered_up_to' => (int) ($row->delivered_up_to ?? 0),
            'read_up_to'      => (int) ($row->read_up_to ?? 0),
        ];
    }

    protected function uuidForMessageId(int $id): ?string
    {
        return $id > 0 && $id !== PHP_INT_MAX
            ? Message::whereKey($id)->value('uuid')
            : null;
    }

    /** @throws ChatException */
    public function setMuted(Conversation $conversation, User $viewer, bool $muted): ConversationParticipant
    {
        $mine = $this->participantOrFail($conversation, $viewer);

        $mine->forceFill(['is_muted' => $muted])->save();

        return $mine->refresh();
    }

    /**
     * Delete a message — for the caller, or for everyone.
     *
     * "For everyone" is the sender's own privilege. Anyone else removing a message from
     * everybody's history is moderation, and that goes through the admin panel with an
     * audit row, not through this endpoint.
     *
     * @throws ChatException
     */
    public function deleteMessage(Message $message, User $actor, bool $forEveryone): void
    {
        $conversation = $message->conversation;

        $this->participantOrFail($conversation, $actor);

        if ($forEveryone) {
            if ($message->sender_id !== $actor->id) {
                throw new ChatException('FORBIDDEN', 'Only the sender can delete a message for everyone.', 403);
            }

            $message->forceFill(['is_deleted' => true, 'body' => null, 'media_url' => null, 'media_meta' => null])->save();

            MessageDeleted::dispatch($conversation->uuid, $message->uuid);

            return;
        }

        $hidden = $message->deleted_for ?? [];

        if (! in_array($actor->id, $hidden, true)) {
            $hidden[] = $actor->id;
            $message->forceFill(['deleted_for' => $hidden])->save();
        }
    }

    /** @throws ChatException */
    public function leave(Conversation $conversation, User $user): void
    {
        $mine = $this->participantOrFail($conversation, $user);

        $mine->forceFill(['left_at' => now(), 'unread_count' => 0])->save();
    }

    // ------------------------------------------------------------------ helpers

    /** @throws ChatException */
    public function participantOrFail(Conversation $conversation, User $user): ConversationParticipant
    {
        $mine = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();

        if ($mine === null) {
            throw ChatException::notAParticipant();
        }

        return $mine;
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, string>
     */
    protected function uuidsFor(array $userIds): array
    {
        return $userIds === []
            ? []
            : User::whereIn('id', $userIds)->pluck('uuid')->all();
    }

    protected function notify(int $userId, Conversation $conversation, User $sender, string $body): void
    {
        Notification::create([
            'user_id' => $userId,
            'type'    => 'message.new',
            'title'   => $sender->profile?->display_name ?? $sender->guftagu_id ?? 'New message',
            // A preview, not the message. A lock-screen notification is read by whoever is
            // holding the phone, which is not always the person it was sent to.
            'body'    => mb_substr($body, 0, 120),
            'data'    => ['conversation_uuid' => $conversation->uuid, 'sender_uuid' => $sender->uuid],
            'channel' => 'push',
            'sent_at' => now(),
        ]);
    }
}
