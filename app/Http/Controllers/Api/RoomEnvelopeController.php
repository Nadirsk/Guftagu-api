<?php

namespace App\Http\Controllers\Api;

use App\Domain\Rooms\RoomEnvelopeService;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomRedEnvelope;
use App\Support\ApiResponse;
use App\Support\SocialPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Red packets inside a room: drop one, see the open ones, open one. */
class RoomEnvelopeController extends Controller
{
    public function __construct(protected RoomEnvelopeService $envelopes)
    {
    }

    /** POST /rooms/{room}/envelopes — `{coins, count}` */
    public function store(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate([
            'coins' => ['required', 'integer', 'min:1', 'max:10000000'],
            'count' => ['required', 'integer', 'min:1', 'max:'.RoomEnvelopeService::MAX_COUNT],
        ]);

        $envelope = $this->envelopes->create($room, $request->user(), (int) $data['coins'], (int) $data['count']);

        return ApiResponse::success($this->present($envelope), 'Red packet sent', 201);
    }

    /** GET /rooms/{room}/envelopes — packets still open that you have not opened. */
    public function index(Request $request, Room $room): JsonResponse
    {
        return ApiResponse::success(
            $this->envelopes->openFor($room, $request->user())->map(fn (RoomRedEnvelope $e) => $this->present($e))->all()
        );
    }

    /** POST /rooms/{room}/envelopes/{envelope}/claim */
    public function claim(Request $request, Room $room, string $envelope): JsonResponse
    {
        $res = $this->envelopes->claim($room, $envelope, $request->user());

        return ApiResponse::success([
            'amount'   => $res['amount'],
            'envelope' => $this->present($res['envelope']),
            'claims'   => $res['claims']->map(fn ($c) => [
                'user'   => SocialPresenter::user($c->user),
                'amount' => $c->amount,
                'at'     => $c->created_at?->toIso8601ZuluString(),
            ])->all(),
        ], 'Red packet opened');
    }

    /** @return array<string, mixed> */
    protected function present(RoomRedEnvelope $e): array
    {
        return [
            'uuid'            => $e->uuid,
            'sender'          => SocialPresenter::user($e->sender),
            'total_coins'     => $e->total_coins,
            'total_count'     => $e->total_count,
            'remaining_count' => $e->remaining_count,
        ];
    }
}
