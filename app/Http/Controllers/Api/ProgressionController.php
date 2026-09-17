<?php

namespace App\Http\Controllers\Api;

use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WealthCharmLevel;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** D.7b — wealth and charm progression, with the next threshold to show "X to go". */
class ProgressionController extends Controller
{
    public function __construct(protected WalletService $wallets)
    {
    }

    /** GET /progression */
    public function show(Request $request): JsonResponse
    {
        $wallet = $this->wallets->forUser($request->user());

        return ApiResponse::success([
            'wealth' => $this->track($wallet, 'wealth', $wallet->lifetime_coins_spent),
            'charm'  => $this->track($wallet, 'charm', $wallet->lifetime_diamonds_earned),
        ]);
    }

    protected function track(Wallet $wallet, string $type, int $lifetimeValue): array
    {
        $current = $type === 'wealth' ? $wallet->wealthLevel() : $wallet->charmLevel();
        $next = WealthCharmLevel::nextAfter($type, $lifetimeValue);

        return [
            'level'          => $current?->level,
            'name_en'        => $current?->name_en,
            'badge_url'      => $current?->badge_url,
            'lifetime_value' => $lifetimeValue,
            'next_level'     => $next === null ? null : [
                'level'     => $next->level,
                'name_en'   => $next->name_en,
                'threshold' => $next->threshold,
                'remaining' => max(0, $next->threshold - $lifetimeValue),
            ],
        ];
    }
}
