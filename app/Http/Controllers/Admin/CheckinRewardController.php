<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CheckinReward;
use App\Models\StoreItem;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Epic D.7c — the admin side of the 7-day check-in ladder. Always exactly 7 rows (one per
 * `streak_day`); `store`/`update` upsert a day's slot rather than the panel building a
 * freeform list, since "day 4" is a fixed concept, not an item an admin invents.
 */
class CheckinRewardController extends Controller
{
    public function index(): JsonResponse
    {
        $rewards = CheckinReward::query()->with('storeItem')->orderBy('streak_day')->get();

        return ApiResponse::success($rewards->map(fn (CheckinReward $r) => $this->payload($r)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $existing = CheckinReward::query()->where('streak_day', $data['streak_day'])->first();

        if ($existing !== null) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                "Day {$data['streak_day']} already has a reward — update it instead.",
                null,
                422,
            );
        }

        $reward = CheckinReward::create($data)->load('storeItem');

        return ApiResponse::success($this->payload($reward), 'Reward saved', 201);
    }

    public function update(Request $request, CheckinReward $checkinReward): JsonResponse
    {
        $data = $this->validated($request, $checkinReward);

        $checkinReward->update($data);

        return ApiResponse::success($this->payload($checkinReward->fresh('storeItem')), 'Reward updated');
    }

    public function destroy(CheckinReward $checkinReward): JsonResponse
    {
        $checkinReward->delete();

        return ApiResponse::success(null, 'Reward removed');
    }

    protected function validated(Request $request, ?CheckinReward $ignoring = null): array
    {
        $data = $request->validate([
            'streak_day'   => [
                'required', 'integer', 'between:1,7',
                Rule::unique('checkin_rewards', 'streak_day')->ignore($ignoring),
            ],
            'reward_type'  => ['required', 'string', Rule::in(CheckinReward::types())],
            'reward_value' => ['required_if:reward_type,coins,diamonds', 'nullable', 'integer', 'min:1'],
            'store_item_id' => ['required_if:reward_type,'.implode(',', StoreItem::TYPES), 'nullable', 'integer', 'exists:store_items,id'],
            'duration_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'icon_url'     => ['sometimes', 'nullable', 'url', 'max:500'],
            'is_active'    => ['sometimes', 'boolean'],
        ]);

        if (CheckinReward::isItemType($data['reward_type'])) {
            $item = StoreItem::find($data['store_item_id']);

            if ($item === null || $item->type !== $data['reward_type']) {
                throw ValidationException::withMessages([
                    'store_item_id' => ['Pick an item of the chosen type.'],
                ]);
            }

            $data['reward_value'] = 1;
        } else {
            $data['store_item_id'] = null;
            $data['duration_days'] = null;
        }

        return $data;
    }

    protected function payload(CheckinReward $reward): array
    {
        return [
            'id'           => $reward->id,
            'streak_day'   => $reward->streak_day,
            'reward_type'  => $reward->reward_type,
            'reward_value' => $reward->reward_value,
            'store_item_id' => $reward->store_item_id,
            'duration_days' => $reward->duration_days,
            'item'         => $reward->itemPayload(),
            'icon_url'     => $reward->icon_url,
            'is_active'    => $reward->is_active,
            'payable'      => $reward->isPayable(),
        ];
    }
}
