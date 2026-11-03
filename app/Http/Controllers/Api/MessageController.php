<?php

namespace App\Http\Controllers\Api;

use App\Domain\Chat\ChatService;
use App\Domain\Social\SocialException;
use App\Domain\Store\GiftSendService;
use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\Gift;
use App\Models\Message;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Cursor;
use App\Support\SocialPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** GFT-236 — messages within a thread (epic D.4). docs/03 §8. */
class MessageController extends Controller
{
    public function __construct(protected ChatService $chat, protected GiftSendService $gifts)
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
            // Gift cards are written by `gift()` after the coins move, never posted by a client.
            'type'        => ['sometimes', Rule::in([Message::TEXT, Message::IMAGE, Message::AUDIO, Message::VIDEO])],
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

    /**
     * POST /conversations/{uuid}/gifts — `{gift_id, quantity?}`. A gift inside a direct thread:
     * the coins move through the same service the rooms use, then a `gift` card is posted so
     * both sides see it in the conversation.
     */
    public function gift(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'gift_id'  => ['required', 'integer', 'exists:gifts,id'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:177'],
        ]);

        $sender = $request->user();
        $this->chat->participantOrFail($conversation, $sender);

        if ($conversation->type !== Conversation::DIRECT) {
            return ApiResponse::error('VALIDATION_ERROR', 'Gifts can only be sent in a one-to-one chat.', null, 422);
        }

        $receiverId = collect($conversation->participantIds())->first(fn (int $id) => $id !== $sender->id);
        $receiver = User::findOrFail($receiverId);

        if (Block::existsBetween($sender->id, $receiver->id)) {
            throw SocialException::blocked();
        }

        $gift = Gift::findOrFail($data['gift_id']);
        $quantity = (int) ($data['quantity'] ?? 1);

        $this->gifts->send($sender, $receiver, $gift, $quantity, null, $request->header('X-Idempotency-Key'));

        $message = $this->chat->send($conversation, $sender, [
            'type'       => Message::GIFT,
            'media_meta' => [
                'gift_id'       => $gift->id,
                'code'          => $gift->code,
                'name'          => $gift->name_en,
                'thumbnail_url' => $gift->thumbnail_url,
                'quantity'      => $quantity,
                'coin_value'    => $gift->coin_price * $quantity,
            ],
        ]);

        return ApiResponse::success([
            'message' => SocialPresenter::message(
                $message,
                $sender->id,
                $this->chat->statusMarks($conversation, $sender),
            ) + ['conversation_uuid' => $conversation->uuid],
            'coin_balance' => $sender->fresh()->wallet?->coin_balance ?? 0,
        ], 'Gift sent', 201);
    }
}
