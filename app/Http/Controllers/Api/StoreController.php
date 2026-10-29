<?php

namespace App\Http\Controllers\Api;

use App\Domain\Store\InventoryService;
use App\Domain\Store\LevelException;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Models\StoreItem;
use App\Models\UserStoreItem;
use App\Models\Wallet;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * D.7 — the cosmetics store: frames, chat bubbles, entry banners, entrance effects.
 * One owned item per type can be worn at a time (`user_store_items.equipped_at`).
 */
class StoreController extends Controller
{
    public function __construct(
        protected InventoryService $inventory,
        protected WalletService $wallets,
    ) {
    }

    /** GET /store/items — `?type=` one of `StoreItem::TYPES`. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['sometimes', Rule::in(StoreItem::TYPES)]]);

        $items = StoreItem::query()
            ->where('is_active', true)
            ->when($data['type'] ?? null, fn ($q, string $t) => $q->where('type', $t))
            ->orderBy('coin_price')
            ->get();

        return ApiResponse::success($items->map(fn (StoreItem $i) => $this->payload($i))->all());
    }

    /** GET /store/items/mine */
    public function mine(Request $request): JsonResponse
    {
        $owned = $this->inventory->activeItems($request->user());

        return ApiResponse::success($owned->map(fn (UserStoreItem $o) => [
            ...$this->payload($o->storeItem),
            'expires_at' => $o->expires_at?->toIso8601ZuluString(),
            'source'     => $o->source,
            'equipped'   => $o->equipped_at !== null,
        ])->all());
    }

    /** POST /store/items/{item}/equip — wear it; takes off whatever of that type was on. */
    public function equip(Request $request, StoreItem $item): JsonResponse
    {
        if ($this->inventory->equip($request->user(), $item) === null) {
            throw new LevelException('NOT_OWNED', 'You do not own that item, or it has expired.', 422);
        }

        return ApiResponse::success(['store_item_id' => $item->id, 'equipped' => true], 'Equipped');
    }

    /** DELETE /store/items/{item}/equip */
    public function unequip(Request $request, StoreItem $item): JsonResponse
    {
        $this->inventory->unequip($request->user(), $item);

        return ApiResponse::success(['store_item_id' => $item->id, 'equipped' => false], 'Unequipped');
    }

    /** POST /store/items/{item}/purchase */
    public function purchase(Request $request, StoreItem $item): JsonResponse
    {
        if (! $item->is_active) {
            throw new LevelException('NOT_FOUND', 'That item is not available.', 404);
        }

        if ($item->required_vip_tier_id !== null && ! $this->inventory->meetsTier($request->user(), $item->required_vip_tier_id)) {
            throw new LevelException('VIP_TIER_REQUIRED', 'Your VIP tier is not high enough for that item.', 403);
        }

        $this->wallets->debitSystem($request->user(), Wallet::COIN, max(1, $item->coin_price), 'store_purchase');

        $owned = $this->inventory->grantStoreItem($request->user(), $item, $item->rental_days, 'purchase');

        return ApiResponse::success([
            'store_item_id' => $owned->store_item_id,
            'expires_at'    => $owned->expires_at?->toIso8601ZuluString(),
        ], 'Purchased');
    }

    protected function payload(StoreItem $item): array
    {
        return [
            'id'                   => $item->id,
            'type'                 => $item->type,
            'name'                 => $item->name,
            'image_url'            => $item->image_url,
            'animation_url'        => $item->animation_url,
            'animation_type'       => $item->animation_type,
            'coin_price'           => $item->coin_price,
            'rental_days'          => $item->rental_days,
            'required_vip_tier_id' => $item->required_vip_tier_id,
        ];
    }
}
