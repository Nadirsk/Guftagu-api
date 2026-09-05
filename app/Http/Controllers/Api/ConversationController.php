<?php

namespace App\Http\Controllers\Api;

use App\Domain\Chat\ChatService;
use App\Events\Chat\UserTyping;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\SocialPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** GFT-235 — the DM list and thread lifecycle (epic D.4). docs/03 §8. */
class ConversationController extends Controller
{
    public function __construct(protected ChatService $chat)
    {
    }

    /** GET /conversations */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $me = $request->user();
        $rows = $this->chat->conversations($me, (int) ($data['limit'] ?? 30));

        $lastMessages = $this->chat->lastMessages(
            $rows->pluck('conversation_id')->all()
        );

        return ApiResponse::success(
            $rows->map(function (ConversationParticipant $mine) use ($me, $lastMessages) {
                $conversation = $mine->conversation;

                $others = $conversation->activeParticipants
                    ->reject(fn (ConversationParticipant $p) => $p->user_id === $me->id)
                    ->map(fn (ConversationParticipant $p) => $p->user)
                    ->filter()
                    ->values()
                    ->all();

                return SocialPresenter::conversation(
                    $conversation,
                    $mine,
                    $lastMessages[$conversation->id] ?? null,
                    $others,
                );
            })->all()
        );
    }

    /**
     * POST /conversations
     *
     * `{user_uuid}` opens (or reopens) a direct thread; `{title, member_uuids}` starts a
     * group. Direct is find-or-create, so tapping "message" twice does not produce two
     * threads with half the history in each.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'           => ['sometimes', Rule::in(Conversation::TYPES)],
            'user_uuid'      => ['required_without:member_uuids', 'nullable', 'uuid'],
            'title'          => ['required_with:member_uuids', 'nullable', 'string', 'max:100'],
            'member_uuids'   => ['sometimes', 'array', 'min:1', 'max:49'],
            'member_uuids.*' => ['uuid'],
        ]);

        $me = $request->user();

        if (! empty($data['member_uuids'])) {
            $members = User::whereIn('uuid', $data['member_uuids'])->get()->all();

            $conversation = $this->chat->createGroup($me, $data['title'], $members);
        } else {
            $other = User::where('uuid', $data['user_uuid'])->firstOrFail();

            $conversation = $this->chat->directWith($me, $other);
        }

        return ApiResponse::success(
            ['conversation' => $this->present($conversation, $me)],
            'Conversation ready',
            201,
        );
    }

    /** GET /conversations/{uuid} */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->chat->participantOrFail($conversation, $request->user());

        return ApiResponse::success(['conversation' => $this->present($conversation, $request->user())]);
    }

    /**
     * POST /conversations/{uuid}/delivered
     *
     * Second tick. The app calls this when a `message.new` frame lands — the thread does not
     * have to be open, which is the whole point of a delivered receipt.
     */
    public function delivered(Request $request, Conversation $conversation): JsonResponse
    {
        $mine = $this->chat->markDelivered($conversation, $request->user());

        return ApiResponse::success([
            'last_delivered_message_uuid' => $this->messageUuid($mine->last_delivered_message_id),
        ], 'Marked delivered');
    }

    /**
     * POST /messages/delivered
     *
     * The app-resume sweep across every thread. A phone that was offline comes back to
     * twenty threads behind, and twenty round trips to set twenty ticks is a bad first
     * second in the app.
     */
    public function deliveredAll(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ['threads_updated' => $this->chat->markAllDelivered($request->user())],
            'Marked delivered',
        );
    }

    /** POST /conversations/{uuid}/read — blue tick, and clears the badge. */
    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $mine = $this->chat->markRead($conversation, $request->user());

        return ApiResponse::success([
            'unread_count' => (int) $mine->unread_count,
            // Echoed back as uuids — the row ids they are stored as would tell the app how
            // many messages the whole platform has sent (docs/03 §2.4).
            'last_read_message_uuid'      => $this->messageUuid($mine->last_read_message_id),
            'last_delivered_message_uuid' => $this->messageUuid($mine->last_delivered_message_id),
        ], 'Marked read');
    }

    protected function messageUuid(?int $id): ?string
    {
        return $id === null ? null : Message::whereKey($id)->value('uuid');
    }

    /** POST /conversations/{uuid}/mute */
    public function mute(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate(['muted' => ['sometimes', 'boolean']]);

        $mine = $this->chat->setMuted(
            $conversation,
            $request->user(),
            $request->has('muted') ? (bool) $data['muted'] : true,
        );

        return ApiResponse::success(['is_muted' => (bool) $mine->is_muted], 'Updated');
    }

    /**
     * POST /conversations/{uuid}/typing
     *
     * Nothing is written down — a typing state is true for about two seconds. The frame
     * goes out and is forgotten.
     */
    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $this->chat->participantOrFail($conversation, $request->user());

        $data = $request->validate(['typing' => ['sometimes', 'boolean']]);

        broadcast(new UserTyping(
            $conversation->uuid,
            $request->user(),
            $request->has('typing') ? (bool) $data['typing'] : true,
        ))->toOthers();

        return ApiResponse::success(null, 'OK');
    }

    /** POST /conversations/{uuid}/leave */
    public function leave(Request $request, Conversation $conversation): JsonResponse
    {
        $this->chat->leave($conversation, $request->user());

        return ApiResponse::success(null, 'Left the conversation');
    }

    protected function present(Conversation $conversation, User $me): array
    {
        $conversation->load('activeParticipants.user.profile:id,user_id,display_name,avatar_url');

        $mine = $conversation->activeParticipants
            ->firstWhere('user_id', $me->id);

        $others = $conversation->activeParticipants
            ->reject(fn (ConversationParticipant $p) => $p->user_id === $me->id)
            ->map(fn (ConversationParticipant $p) => $p->user)
            ->filter()
            ->values()
            ->all();

        $last = $this->chat->lastMessages([$conversation->id])[$conversation->id] ?? null;

        return SocialPresenter::conversation($conversation, $mine, $last, $others);
    }
}
