<?php

namespace App\Http\Controllers\Api;

use App\Domain\Store\InventoryService;
use App\Domain\Store\LevelException;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Models\VipTier;
use App\Models\Wallet;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * D.7a/b — VIP tiers and purchase. ⚠ CI-02 supplies the real pricing.
 *
 * Only `pay_with: coins` is implemented — the `gateway` path in the original docs/03 spec
 * needs the same Razorpay integration `RechargeController`'s doc-block explains is missing.
 */
class VipController extends Controller
{
    public function __construct(
        protected InventoryService $inventory,
        protected WalletService $wallets,
    ) {
    }

    /** GET /vip/tiers */
    public function tiers(): JsonResponse
    {
        $tiers = VipTier::query()->where('is_active', true)->orderBy('level')->get();

        return ApiResponse::success($tiers->map(fn (VipTier $t) => [
            'id'                    => $t->id,
            'level'                 => $t->level,
            'name_en'               => $t->name_en,
            'name_hi'               => $t->name_hi,
            'badge_url'             => $t->badge_url,
            'frame_url'             => $t->frame_url,
            'monthly_price_paise'   => $t->monthly_price_paise,
            'quarterly_price_paise' => $t->quarterly_price_paise,
            'yearly_price_paise'    => $t->yearly_price_paise,
            'coin_price'            => $t->coin_price,
            'privileges'            => $t->privileges ?? [],
        ])->all());
    }

    /** GET /vip/me */
    public function me(Request $request): JsonResponse
    {
        $active = $this->inventory->activeVip($request->user());

        return ApiResponse::success($active === null ? null : [
            'tier_id'    => $active->vip_tier_id,
            'level'      => $active->vipTier->level,
            'name_en'    => $active->vipTier->name_en,
            'expires_at' => $active->expires_at->toIso8601ZuluString(),
        ]);
    }

    /** POST /vip/purchase — `{tier_id, duration: monthly|quarterly|yearly}`, paid with coins. */
    public function purchase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tier_id'  => ['required', 'integer', 'exists:vip_tiers,id'],
            'duration' => ['required', Rule::in(['monthly', 'quarterly', 'yearly'])],
        ]);

        $tier = VipTier::where('id', $data['tier_id'])->where('is_active', true)->first();

        if ($tier === null) {
            throw new LevelException('NOT_FOUND', 'That VIP tier is not available.', 404);
        }

        $days = match ($data['duration']) {
            'monthly'   => 30,
            'quarterly' => 90,
            'yearly'    => 365,
        };

        // `coin_price` is the tier's monthly rate — there is one field, not one per
        // duration, so a longer term scales it rather than needing three columns.
        $coinPrice = (int) ceil($tier->coin_price * $days / 30);

        $this->wallets->debitSystem($request->user(), Wallet::COIN, max(1, $coinPrice), 'vip_purchase');

        $subscription = $this->inventory->grantVip($request->user(), $tier, $days, 'purchase');

        return ApiResponse::success([
            'tier_id'    => $subscription->vip_tier_id,
            'expires_at' => $subscription->expires_at->toIso8601ZuluString(),
        ], 'VIP activated');
    }
}
