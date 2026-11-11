<?php

namespace App\Http\Controllers\Api;

use App\Domain\Store\GiftSendService;
use App\Http\Controllers\Controller;
use App\Models\Gift;
use App\Models\GiftTransaction;
use App\Models\Room;
use App\Models\RoomMember;
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
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:177'],
        ]);

        $gift = Gift::findOrFail($data['gift_id']);
        $quantity = (int) ($data['quantity'] ?? 1);
        $idempotencyKey = $request->header('X-Idempotency-Key');
        $sender = $request->user();

        // "all" is everyone on a mic — the sender too, as in Mazag; each gets the full quantity.
        if ($data['to_user'] === 'all') {
            $receivers = $room->seats()->whereNotNull('user_id')->with('user')->get()
                ->pluck('user')->filter()->unique('id')->values();

            if ($receivers->isEmpty()) {
                return ApiResponse::error('NO_RECEIVERS', 'Nobody is on a mic to receive that.', null, 422);
            }

            $total = $gift->coin_price * $quantity * $receivers->count();
            if (($sender->wallet?->coin_balance ?? 0) < $total) {
                return ApiResponse::error('INSUFFICIENT_COINS', 'Not enough coins to send to everyone.', null, 402);
            }

            $batch = (string) \Illuminate\Support\Str::uuid();
            $last = null;
            foreach ($receivers as $receiver) {
                $last = $this->gifts->send(
                    $sender, $receiver, $gift, $quantity, $room,
                    $idempotencyKey ? "{$idempotencyKey}:{$receiver->id}" : null,
                    $batch,
                );
            }

            return ApiResponse::success([
                'transaction_id' => $last[0]->id,
                'coin_balance'   => $sender->fresh()->wallet?->coin_balance ?? 0,
                'gift'           => ['code' => $gift->code, 'animation_url' => $gift->animation_url, 'animation_type' => $gift->animation_type, 'is_fullscreen' => $gift->is_fullscreen, 'duration_ms' => $gift->duration_ms],
                'combo_count'    => $last[1],
                'receiver'       => null,
                'receivers'      => $receivers->map(fn (User $u) => SocialPresenter::user($u))->all(),
                'all'            => true,
            ], 'Gift sent');
        }

        $receiver = User::where('uuid', $data['to_user'])->firstOrFail();

        [$transaction, $comboCount] = $this->gifts->send(
            $sender,
            $receiver,
            $gift,
            $quantity,
            $room,
            $idempotencyKey,
        );

        $wallet = $sender->fresh()->wallet;

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
    public function index(Request $request, Room $room): JsonResponse
    {
        // Same rule as the chat: only gifts sent since this person's current arrival. Someone
        // who is not inside the room sees none.
        $joinedAt = RoomMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->value('joined_at');

        if ($joinedAt === null) {
            return ApiResponse::success([]);
        }

        $rows = GiftTransaction::where('room_id', $room->id)
            ->where('created_at', '>=', $joinedAt)
            ->with(['sender.profile:id,user_id,display_name,avatar_url,gender', 'receiver.profile:id,user_id,display_name,avatar_url,gender', 'gift:id,code,thumbnail_url,tier'])
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
