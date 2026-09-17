<?php

namespace App\Http\Controllers\Api;

use App\Domain\Store\GiftSendService;
use App\Http\Controllers\Controller;
use App\Models\Gift;
use App\Models\GiftTransaction;
use App\Models\Room;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\SocialPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** D.6a — sending a gift inside a room, and its recent feed (docs/03 §6). */
class RoomGiftController extends Controller
{
    public function __construct(protected GiftSendService $gifts)
    {
    }

    /** POST /rooms/{room}/gifts — `{gift_id, to_user, quantity?}`, `X-Idempotency-Key` recommended. */
    public function store(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate([
            'gift_id'  => ['required', 'integer', 'exists:gifts,id'],
            'to_user'  => ['required', 'string'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:99'],
        ]);

        $gift = Gift::findOrFail($data['gift_id']);
        $receiver = User::where('uuid', $data['to_user'])->firstOrFail();
        $idempotencyKey = $request->header('X-Idempotency-Key');

        [$transaction, $comboCount] = $this->gifts->send(
            $request->user(),
            $receiver,
            $gift,
            (int) ($data['quantity'] ?? 1),
            $room,
            $idempotencyKey,
        );

        $wallet = $request->user()->fresh()->wallet;

        return ApiResponse::success([
            'transaction_id' => $transaction->id,
            'coin_balance'   => $wallet?->coin_balance ?? 0,
            'gift'           => [
                'code'           => $gift->code,
                'animation_url'  => $gift->animation_url,
                'animation_type' => $gift->animation_type,
                'is_fullscreen'  => $gift->is_fullscreen,
                'duration_ms'    => $gift->duration_ms,
            ],
            'combo_count' => $comboCount,
            'receiver'    => SocialPresenter::user($receiver),
        ], 'Gift sent');
    }

    /** GET /rooms/{room}/gifts — the recent feed, newest first. */
    public function index(Room $room): JsonResponse
    {
        $rows = GiftTransaction::where('room_id', $room->id)
            ->with(['sender.profile:id,user_id,display_name,avatar_url', 'receiver.profile:id,user_id,display_name,avatar_url', 'gift:id,code,thumbnail_url,tier'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return ApiResponse::success($rows->map(fn (GiftTransaction $t) => [
            'sender'     => SocialPresenter::user($t->sender),
            'receiver'   => SocialPresenter::user($t->receiver),
            'gift'       => ['code' => $t->gift->code, 'thumbnail_url' => $t->gift->thumbnail_url, 'tier' => $t->gift->tier],
            'quantity'   => $t->quantity,
            'created_at' => $t->created_at?->toIso8601ZuluString(),
        ])->all());
    }
}
