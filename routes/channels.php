<?php

use App\Domain\Access\Services\PermissionResolver;
use App\Domain\Social\SocialService;
use App\Models\AdminUser;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channel authorisation
|--------------------------------------------------------------------------
| docs/01 §4.3, docs/03 §13.
|
| `/broadcasting/auth` accepts either guard (see bootstrap/app.php), so **every callback
| below must check the type of who it was handed**, not just their id. Without that, an
| AdminUser with id 7 authorises `user.{uuid-of-app-user-7}` — two tables, one id space,
| and the subscription is granted to the wrong person entirely.
|
| The default is deny: a channel with no callback here cannot be subscribed to at all.
*/

// ---------------------------------------------------------------------- mobile

/**
 * Everything addressed to one person: `follow.new`, `message.new`, `notification.new`,
 * `post.liked`, `post.commented`, `visitor.new`, `wallet.updated`, `call.*`.
 *
 * Matched on uuid, never on the numeric id — docs/03 §2.4, and it means a leaked channel
 * name reveals nothing enumerable.
 */
Broadcast::channel('user.{uuid}', function ($user, string $uuid) {
    return $user instanceof User && $user->uuid === $uuid;
});

/**
 * A moment's live thread — likes and comments as they land.
 *
 * Authorised by the same visibility clause the REST endpoints use, so a `followers`-only
 * post cannot be watched by a non-follower over the socket. D.3d's "cannot see it via the
 * feed **or** by direct id" would be worth very little if the WebSocket were a third way in.
 */
Broadcast::channel('post.{uuid}', function ($user, string $uuid) {
    if (! $user instanceof User) {
        return false;
    }

    $post = Post::where('uuid', $uuid)->first();

    return $post !== null && $post->isVisibleTo($user);
});

/**
 * A chat thread. Participants only, never across a block (D.9c), and on a direct thread only
 * while the two still follow each other — the same gate `ChatService::send()` applies.
 *
 * The socket has to enforce it too. A thread that refuses new messages but keeps streaming
 * them over the WebSocket is not closed, it is closed in one direction.
 *
 * Someone who left the conversation keeps their `left_at` row and stops here: they may still
 * read the history they have, but they receive nothing said after they left.
 */
Broadcast::channel('conversation.{uuid}', function ($user, string $uuid) {
    if (! $user instanceof User) {
        return false;
    }

    $conversation = Conversation::where('uuid', $uuid)->first();

    if ($conversation === null || ! $conversation->hasParticipant($user->id)) {
        return false;
    }

    if ($conversation->type !== Conversation::DIRECT) {
        return true;
    }

    $otherId = collect($conversation->participantIds())->first(fn (int $id) => $id !== $user->id);

    if ($otherId === null) {
        return true;
    }

    return ! Block::existsBetween($user->id, $otherId)
        && app(SocialService::class)->areFriends($user->id, $otherId);
});

// ----------------------------------------------------------------------- admin

/**
 * docs/03 §13 — the moderation firehose and the KPI ticker.
 *
 * Gated through {@see PermissionResolver}, the same object the `permission:` middleware
 * uses. docs/01 §4.3 asks for "one gate, used by HTTP middleware, WebSocket channel auth,
 * Vue route guards" — a second implementation here would be a second answer to the same
 * question, and it would be the one nobody remembers to update.
 */
Broadcast::channel('admin.moderation', function ($user) {
    return $user instanceof AdminUser && app(PermissionResolver::class)->has($user, 'moderation.live');
});

Broadcast::channel('admin.dashboard', function ($user) {
    return $user instanceof AdminUser && app(PermissionResolver::class)->has($user, 'dashboard.view');
});
