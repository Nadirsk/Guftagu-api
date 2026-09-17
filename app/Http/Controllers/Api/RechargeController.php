<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RechargePackage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * D.6d (the browsing half only). ⚠ CI-01 supplies the real pricing.
 *
 * There is deliberately no `POST /recharge/orders` here — that needs a live Razorpay
 * order-creation call and webhook, which needs the account credentials in ROADMAP.md
 * §7 CI-04 (not yet supplied). Building an endpoint that mints a fake order id would
 * look finished and would not be — see the audit at
 * `docs/GUFTAGU_SCOPE_AUDIT_FR_D_USER_PLAYER.md` for the full note on this gap.
 */
class RechargeController extends Controller
{
    /** GET /recharge-packages */
    public function packages(): JsonResponse
    {
        $packages = RechargePackage::query()
            ->sellable()
            ->orderBy('sort_order')->orderBy('price_paise')
            ->get();

        return ApiResponse::success($packages->map(fn (RechargePackage $p) => [
            'id'                     => $p->id,
            'name'                   => $p->name,
            'coins'                  => $p->coins,
            'bonus_coins'            => $p->bonus_coins,
            'total_coins'            => $p->totalCoins(),
            'price_paise'            => $p->price_paise,
            'is_first_purchase_only' => $p->is_first_purchase_only,
            'badge_text'             => $p->badge_text,
        ])->all());
    }
}
