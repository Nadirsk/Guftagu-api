<?php

namespace App\Http\Controllers\Api;

use App\Domain\Engagement\LuckyWheelService;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Models\LuckyWheel;
use App\Models\LuckyWheelPrize;
use App\Models\LuckyWheelSpin;
use App\Models\Room;
use App\Models\StoreItem;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Lucky Wheel in a room: see both wheels, spin one, look back at your own spins. */
class LuckyWheelController extends Controller
{
    public function __construct(protected LuckyWheelService $wheels, protected WalletService $wallets)
    {
    }

    /** GET /lucky-wheel — both wheels, their eight slots and prices, plus your coin balance. */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'coin_balance' => (int) $this->wallets->forUser($request->user())->coin_balance,
            'wheels'       => $this->wheels->wheels()->map(fn (LuckyWheel $w) => [
                'mode'        => $w->mode,
                'name'        => $w->name,
                'is_active'   => $w->is_active,
                'spin_cost'   => $w->spin_cost,
                'multi_count' => $w->multi_count,
                'multi_cost'  => $w->multi_cost,
                'prizes'      => $w->prizes->map(fn (LuckyWheelPrize $p) => [
                    'position'     => $p->position,
                    'label'        => $p->displayLabel(),
                    'reward_type'  => $p->reward_type,
                    'reward_value' => $p->reward_value,
                    'icon_url'     => $p->icon_url,
                    'item'         => $this->item($p->storeItem),
                ])->values(),
            ])->values(),
        ]);
    }

    /** POST /lucky-wheel/{mode}/spin — `{times: 1|multi_count, room?: uuid}` */
    public function spin(Request $request, string $mode): JsonResponse
    {
        $data = $request->validate([
            'times' => ['required', 'integer', 'min:1', 'max:100'],
            'room'  => ['sometimes', 'nullable', 'uuid'],
        ]);

        abort_unless(in_array($mode, LuckyWheel::MODES, true), 404);

        $room = empty($data['room']) ? null : Room::query()->where('uuid', $data['room'])->first();
        $res = $this->wheels->spin($request->user(), $mode, (int) $data['times'], $room);

        return ApiResponse::success([
            'batch'           => $res['batch'],
            'coin_balance'    => $res['coin_balance'],
            'diamond_balance' => $res['diamond_balance'],
            'results'         => $res['spins']->map(fn (LuckyWheelSpin $s) => $this->spinPayload($s))->values(),
        ], 'Spun');
    }

    /** GET /lucky-wheel/records?mode=&before= — your own spins, newest first, 30 at a time. */
    public function records(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode'   => ['sometimes', 'nullable', Rule::in(LuckyWheel::MODES)],
            'before' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $spins = LuckyWheelSpin::query()
            ->where('user_id', $request->user()->id)
            ->when($data['mode'] ?? null, fn ($q, $mode) => $q->whereHas('wheel', fn ($w) => $w->where('mode', $mode)))
            ->when($data['before'] ?? null, fn ($q, $before) => $q->where('id', '<', $before))
            ->with(['wheel:id,mode,name', 'storeItem'])
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return ApiResponse::success([
            'records' => $spins->map(fn (LuckyWheelSpin $s) => $this->spinPayload($s) + [
                'mode' => $s->wheel?->mode,
                'cost' => $s->cost,
                'at'   => $s->created_at?->toIso8601ZuluString(),
            ])->values(),
            'next_before' => $spins->count() === 30 ? $spins->last()->id : null,
        ]);
    }

    protected function spinPayload(LuckyWheelSpin $s): array
    {
        return [
            'id'            => $s->id,
            'position'      => $s->position,
            'label'         => $s->label,
            'reward_type'   => $s->reward_type,
            'reward_value'  => $s->reward_value,
            'duration_days' => $s->duration_days,
            'item'          => $this->item($s->storeItem),
        ];
    }

    protected function item(?StoreItem $item): ?array
    {
        return $item === null ? null : [
            'id'        => $item->id,
            'type'      => $item->type,
            'name'      => $item->name,
            'image_url' => $item->image_url,
        ];
    }
}
