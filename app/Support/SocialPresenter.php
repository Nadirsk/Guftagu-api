<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;

/**
 * One shape per entity, used by **both** the REST controllers and the broadcast events.
 *
 * This is the point of the class. A client applies a `post.liked` frame to the same object
 * it got from `GET /feed`; if the two are built in different places they drift, and the
 * drift shows up as a card that half-updates in the app and nowhere in the tests. Anything
 * that goes over the wire about a post, comment, message or person is built here.
 *
 * Ids are always uuids. The mobile API never exposes an auto-increment key — `posts/12`
 * being enumerable is a privacy leak on a `followers`-only post even when the endpoint
 * refuses it.
 */
class SocialPresenter
{
    /** The person card that appears on every post, comment, message and list row. */
    public static function user(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $profile = $user->relationLoaded('profile') ? $user->profile : $user->profile()->first();

        return [
            'uuid'         => $user->uuid,
            'guftagu_id'   => $user->guftagu_id,
            'display_name' => $profile?->display_name,
            'avatar_url'   => $profile?->avatar_url,
        ];
    }

    /**
     * @param  bool|null  $liked  whether the caller has liked it; null when not resolved
     */
    public static function post(Post $post, ?bool $liked = null): array
    {
        return array_filter([
            'uuid'          => $post->uuid,
            'author'        => static::user($post->author),
            'type'          => $post->type,
            'body'          => $post->body,
            'media_urls'    => $post->media_urls ?? [],
            'visibility'    => $post->visibility,
            'like_count'    => (int) $post->like_count,
            'comment_count' => (int) $post->comment_count,
            'is_hidden'     => (bool) $post->is_hidden,
            'liked_by_me'   => $liked,
            'created_at'    => $post->created_at?->toIso8601ZuluString(),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<int, string>  $parentUuids  id => uuid, so a page of replies resolves its
     *                                           parents without one query per row
     */
    public static function comment(PostComment $comment, array $parentUuids = []): array
    {
        return [
            'uuid'        => $comment->uuid,
            'post_uuid'   => $comment->relationLoaded('post') ? $comment->post?->uuid : null,
            'author'      => $comment->is_deleted ? null : static::user($comment->author),
            'parent_uuid' => $comment->parent_id === null
                ? null
                : ($parentUuids[$comment->parent_id] ?? $comment->parent?->uuid),
            // A tombstone keeps its place in the thread so replies still hang off something.
            'body'        => $comment->is_deleted ? null : $comment->body,
            'is_deleted'  => (bool) $comment->is_deleted,
            'created_at'  => $comment->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  int|null  $viewerId   whose ticks these are — status is returned only for the
     *                               viewer's own messages, because you do not tick somebody
     *                               else's
     * @param  array{delivered_up_to: int, read_up_to: int}|null  $marks  the thread's
     *                               watermarks, from {@see \App\Domain\Chat\ChatService::statusMarks()}
     */
    public static function message(Message $message, ?int $viewerId = null, ?array $marks = null): array
    {
        return [
            'uuid'              => $message->uuid,
            'conversation_uuid' => $message->relationLoaded('conversation')
                ? $message->conversation?->uuid
                : null,
            'sender'     => static::user($message->sender),
            'type'       => $message->type,
            'body'       => $message->is_deleted ? null : $message->body,
            'media_url'  => $message->is_deleted ? null : $message->media_url,
            'media_meta' => $message->is_deleted ? null : $message->media_meta,
            // The uuid, not `reply_to_id`: docs/03 §2.4, "never leak a sequential id to the
            // app". Callers eager-load `replyTo:id,uuid`, so this costs no extra query.
            'reply_to'   => $message->reply_to_id === null ? null : $message->replyTo?->uuid,
            'is_deleted' => (bool) $message->is_deleted,
            // `sent` · `delivered` · `read` — the ✓ / ✓✓ / ✓✓ blue the sender sees. Null on
            // a message the viewer did not send, and null when the caller did not ask for
            // marks (the DM list's last-message preview does not draw a tick).
            'status'     => static::messageStatus($message, $viewerId, $marks),
            'created_at' => $message->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  array{delivered_up_to: int, read_up_to: int}|null  $marks
     */
    protected static function messageStatus(Message $message, ?int $viewerId, ?array $marks): ?string
    {
        if ($viewerId === null || $marks === null || $message->sender_id !== $viewerId) {
            return null;
        }

        // A system message has no sender and therefore no ticks; the guard above already
        // covers it, since `sender_id` is null and can never equal a viewer id.
        if ($message->id <= $marks['read_up_to']) {
            return 'read';
        }

        return $message->id <= $marks['delivered_up_to'] ? 'delivered' : 'sent';
    }

    /**
     * A DM-list row. `unread_count` and `is_muted` come from the caller's own participant
     * row — the same conversation looks different to each side, which is why the viewer's
     * row is a parameter rather than something read off the conversation.
     */
    public static function conversation(
        Conversation $conversation,
        ?ConversationParticipant $mine = null,
        ?Message $last = null,
        array $others = [],
    ): array {
        return [
            'uuid'            => $conversation->uuid,
            'type'            => $conversation->type,
            'title'           => $conversation->title,
            'avatar_url'      => $conversation->avatar_url,
            'participants'    => array_values(array_filter(array_map(
                fn (User $u) => static::user($u),
                $others,
            ))),
            'last_message'    => $last === null ? null : static::message($last),
            'last_message_at' => $conversation->last_message_at?->toIso8601ZuluString(),
            'unread_count'    => (int) ($mine?->unread_count ?? 0),
            'is_muted'        => (bool) ($mine?->is_muted ?? false),
        ];
    }
}
