<?php

namespace App\Http\Controllers\Api;

use App\Domain\Chat\ChatService;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\ApiResponse;
use App\Support\Cursor;
use App\Support\SocialPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** GFT-236 — messages within a thread (epic D.4). docs/03 §8. */
class MessageController extends Controller
{
    public function __construct(protected ChatService $chat)
    {
    }

    /**
     * GET /conversations/{uuid}/messages
     *
     * Newest first, cursor-paginated (docs/03 §2.3) — a thread grows at the top while you
     * scroll back through it, which is exactly the case `OFFSET` gets wrong.
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->chat->messages(
            $conversation,
            $request->user(),
            Cursor::decode($data['cursor'] ?? null),
            (int) ($data['limit'] ?? 30),
        );

        // One aggregate for the whole page rather than one per row — the tick on thirty
        // messages is thirty comparisons against the same two numbers.
        $marks = $this->chat->statusMarks($conversation, $request->user());

        $items = $page['items']->map(
            fn (Message $m) => SocialPresenter::message($m, $request->user()->id, $marks)
                + ['conversation_uuid' => $conversation->uuid]
        )->all();

        return ApiResponse::cursor(
            $items,
            $page['next_cursor'] === null ? null : Cursor::encode($page['next_cursor']),
        );
    }

    /** POST /conversations/{uuid}/messages */
    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'type'        => ['sometimes', Rule::in(Message::TYPES)],
            'body'        => ['sometimes', 'nullable', 'string', 'max:4000'],
            'media_url'   => ['sometimes', 'nullable', 'string', 'url', 'max:500'],
            'media_meta'    => ['sometimes', 'nullable', 'array'],
            'reply_to_uuid' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $message = $this->chat->send($conversation, $request->user(), $data);

        return ApiResponse::success([
            // Always `sent` at this instant — nobody can have received it yet. Returned
            // anyway so the client has one shape to render and does not special-case the
            // message it just posted.
            'message' => SocialPresenter::message(
                $message,
                $request->user()->id,
                $this->chat->statusMarks($conversation, $request->user()),
            ) + ['conversation_uuid' => $conversation->uuid],
        ], 'Sent', 201);
    }

    /**
     * DELETE /conversations/{conversation}/messages/{message}
     *
     * `?for_everyone=true` retracts it for the whole thread and is the sender's privilege
     * alone; without the flag it is hidden for the caller only.
     */
    public function destroy(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        if ($message->conversation_id !== $conversation->id) {
            return ApiResponse::error('NOT_FOUND', 'Resource not found', null, 404);
        }

        $this->chat->deleteMessage($message, $request->user(), $request->boolean('for_everyone'));

        return ApiResponse::success(null, 'Deleted');
    }
}
