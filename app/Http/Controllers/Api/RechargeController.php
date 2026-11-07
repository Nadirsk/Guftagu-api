<?php

namespace App\Http\Controllers\Api;

use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Models\RechargePackage;
use App\Support\ApiResponse;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    public function __construct(protected WalletService $wallets)
    {
    }

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

    /**
     * POST /recharge-packages/{package}/purchase — until a payment gateway is connected the
     * coins are credited straight away, the way a successful order would. The ledger row is
     * the `recharge` type, so lifetime-purchased and the first-purchase rule keep working.
     */
    public function purchase(Request $request, RechargePackage $package): JsonResponse
    {
        $user = $request->user();

        abort_unless(RechargePackage::query()->sellable()->whereKey($package->id)->exists(), 404);

        if ($package->is_first_purchase_only && $this->wallets->forUser($user)->lifetime_coins_purchased > 0) {
            return ApiResponse::error('NOT_ELIGIBLE', 'That pack is only for your first recharge.', null, 422);
        }

        $wallet = DB::transaction(function () use ($user, $package) {
            $this->wallets->creditSystem($user, Wallet::COIN, $package->totalCoins(), 'recharge');

            return $this->wallets->forUser($user)->refresh();
        });

        return ApiResponse::success([
            'package_id'   => $package->id,
            'coins_added'  => $package->totalCoins(),
            'coin_balance' => $wallet->coin_balance,
        ], 'Coins added');
    }
}
