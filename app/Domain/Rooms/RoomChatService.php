<?php

namespace App\Domain\Rooms;

use App\Domain\Moderation\ContentFilter;
use App\Events\Rooms\RoomMessagePosted;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\RoomMessage;
use App\Models\User;

/**
 * D.2d — in-room chat. Persisted (not a bare client-broadcast event, unlike the WebRTC
 * signalling on the same channel) precisely so C.3's "review flagged text" has a row to
 * review — a message that only ever existed as a socket frame cannot be looked at later.
 */
class RoomChatService
{
    public function __construct(protected ContentFilter $filter)
    {
    }

    /** @throws RoomException */
    public function post(Room $room, User $sender, string $body): RoomMessage
    {
        if ($room->isClosed()) {
            throw new RoomException('ROOM_CLOSED', 'This room has ended.', 410);
        }

        $isMember = RoomMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $sender->id)
            ->where('is_active', true)
            ->exists();

        if (! $isMember) {
            throw new RoomException('NOT_A_MEMBER', 'Join the room before chatting in it.', 409);
        }

        $body = trim($body);

        if ($body === '') {
            throw new RoomException('VALIDATION_ERROR', 'An empty message is not a message.', 422);
        }

        $result = $this->filter->checkAndFlag($body, 'chat', $sender->id);

        if ($result->blocked()) {
            throw new RoomException('BANNED_WORD_DETECTED', 'That message contains words that are not allowed.', 422);
        }

        $message = RoomMessage::create([
            'room_id' => $room->id,
            'user_id' => $sender->id,
            'body'    => $result->filtered,
        ]);

        $message->setRelation('user', $sender);

        RoomMessagePosted::dispatch($room, $message);

        return $message;
    }

    /**
     * Newest-first, cursor-paginated the same way DM history is (docs/03 §2.3) — a room's
     * chat grows at the top while you scroll back, same as a conversation thread.
     *
     * Like Mazag, a person only ever sees chat from their current arrival onward: what was said
     * before they walked in — or before their last exit and re-entry — is never handed to them.
     * Someone who is not inside the room gets nothing at all.
     *
     * @return array{items: \Illuminate\Support\Collection<int, RoomMessage>, next_cursor: ?int}
     */
    public function history(Room $room, User $viewer, ?int $beforeId, int $limit): array
    {
        $joinedAt = RoomMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $viewer->id)
            ->where('is_active', true)
            ->value('joined_at');

        if ($joinedAt === null) {
            return ['items' => collect(), 'next_cursor' => null];
        }

        $query = RoomMessage::query()
            ->where('room_id', $room->id)
            ->where('created_at', '>=', $joinedAt)
            ->with('user.profile:id,user_id,display_name,avatar_url,gender,country');

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
}
