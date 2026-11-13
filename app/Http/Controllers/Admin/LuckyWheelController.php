<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Engagement\LuckyWheelService;
use App\Domain\Media\ImageUploadService;
use App\Http\Controllers\Controller;
use App\Models\LuckyWheel;
use App\Models\LuckyWheelPrize;
use App\Models\StoreItem;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin side of the Lucky Wheel: each wheel's prices and on/off, and its eight slots —
 * what each pays, its label/icon and its weight in the draw. Slots are fixed positions
 * (1-8 round the wheel), so a slot is saved in place rather than added or removed.
 */
class LuckyWheelController extends Controller
{
    public function __construct(
        protected LuckyWheelService $wheels,
        protected AuditLogger $audit,
        protected ImageUploadService $uploads,
    ) {
    }

    /** GET /admin/lucky-wheels — both wheels, their slots with chances, and spin totals. */
    public function index(): JsonResponse
    {
        $totals = DB::table('lucky_wheel_spins')
            ->selectRaw("lucky_wheel_id, COUNT(*) AS spins, COUNT(DISTINCT user_id) AS players, SUM(cost) AS coins_in, SUM(CASE WHEN reward_type = 'coins' THEN reward_value ELSE 0 END) AS coins_out")
            ->groupBy('lucky_wheel_id')
            ->get()
            ->keyBy('lucky_wheel_id');

        return ApiResponse::success($this->wheels->wheels()->map(fn (LuckyWheel $w) => $this->payload($w, $totals[$w->id] ?? null))->values());
    }

    /** PATCH /admin/lucky-wheels/{wheel} — name, prices, on/off. */
    public function update(Request $request, LuckyWheel $wheel): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:60'],
            'spin_cost'   => ['sometimes', 'integer', 'min:1', 'max:10000000'],
            'multi_count' => ['sometimes', 'integer', 'min:2', 'max:100'],
            'multi_cost'  => ['sometimes', 'integer', 'min:1', 'max:1000000000'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        $before = $wheel->only(array_keys($data));
        $wheel->update($data);
        $this->audit->log($request->user(), 'lucky_wheel.update', 'lucky_wheel', LuckyWheel::class, $wheel->id, $before, $data);

        return ApiResponse::success($this->payload($wheel->fresh('prizes.storeItem')), 'Wheel updated');
    }

    /** PUT /admin/lucky-wheels/{wheel}/prizes/{position} — save one slot. */
    public function savePrize(Request $request, LuckyWheel $wheel, int $position): JsonResponse
    {
        abort_unless($position >= 1 && $position <= LuckyWheel::SLOTS, 404);

        $data = $request->validate([
            'label'         => ['sometimes', 'nullable', 'string', 'max:40'],
            'reward_type'   => ['required', 'string', Rule::in(LuckyWheelPrize::types())],
            'reward_value'  => ['required_if:reward_type,coins,diamonds', 'nullable', 'integer', 'min:1', 'max:1000000000'],
            'store_item_id' => ['nullable', 'integer', 'exists:store_items,id'],
            'duration_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'icon_url'      => ['sometimes', 'nullable', 'url', 'max:500'],
            'weight'        => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active'     => ['sometimes', 'boolean'],
        ]);

        if (LuckyWheelPrize::isItemType($data['reward_type'])) {
            $item = StoreItem::find($data['store_item_id'] ?? null);

            if ($item === null || $item->type !== $data['reward_type']) {
                throw ValidationException::withMessages(['store_item_id' => ['Pick an item of the chosen type.']]);
            }

            $data['reward_value'] = 1;
        } else {
            $data['store_item_id'] = null;
            $data['duration_days'] = null;
        }

        $prize = LuckyWheelPrize::query()->firstOrNew(['lucky_wheel_id' => $wheel->id, 'position' => $position]);
        $before = $prize->exists ? $prize->only(array_keys($data)) : null;
        $prize->fill($data)->save();

        $this->audit->log($request->user(), 'lucky_wheel.prize_save', 'lucky_wheel', LuckyWheelPrize::class, $prize->id, $before, $data);

        return ApiResponse::success($this->payload($wheel->fresh('prizes.storeItem')), 'Slot saved');
    }

    /** POST /admin/lucky-wheels/icon — an icon image for a slot; returns its URL. */
    public function uploadIcon(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp,gif'],
        ], ['file.max' => 'That image is larger than 2 MB.']);

        return ApiResponse::success($this->uploads->store($request->file('file'), 'lucky-wheel'), 'Icon uploaded');
    }

    protected function payload(LuckyWheel $wheel, ?object $totals = null): array
    {
        $odds = $this->wheels->odds($wheel);

        return [
            'id'          => $wheel->id,
            'mode'        => $wheel->mode,
            'name'        => $wheel->name,
            'spin_cost'   => $wheel->spin_cost,
            'multi_count' => $wheel->multi_count,
            'multi_cost'  => $wheel->multi_cost,
            'is_active'   => $wheel->is_active,
            'payout_rate' => $odds['payout_rate'],
            'totals'      => [
                'spins'     => (int) ($totals->spins ?? 0),
                'players'   => (int) ($totals->players ?? 0),
                'coins_in'  => (int) ($totals->coins_in ?? 0),
                'coins_out' => (int) ($totals->coins_out ?? 0),
            ],
            'prizes' => $wheel->prizes->map(fn (LuckyWheelPrize $p) => [
                'id'            => $p->id,
                'position'      => $p->position,
                'label'         => $p->label,
                'display_label' => $p->displayLabel(),
                'reward_type'   => $p->reward_type,
                'reward_value'  => $p->reward_value,
                'store_item_id' => $p->store_item_id,
                'duration_days' => $p->duration_days,
                'icon_url'      => $p->icon_url,
                'weight'        => $p->weight,
                'is_active'     => $p->is_active,
                'payable'       => $p->isPayable(),
                'chance'        => $odds['chances'][$p->id] ?? 0,
                'item'          => $p->storeItem === null ? null : [
                    'id' => $p->storeItem->id, 'type' => $p->storeItem->type, 'name' => $p->storeItem->name, 'image_url' => $p->storeItem->image_url,
                ],
            ])->values(),
        ];
    }
}
