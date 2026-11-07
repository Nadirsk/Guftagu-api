<?php

namespace App\Http\Controllers\Api;

use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WealthCharmLevel;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * D.7b — wealth and charm progression, with the next threshold to show "X to go". Both
 * are measured in coins of room gifts: sent for wealth, received for charm.
 */
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
            'wealth' => $this->track($wallet, 'wealth'),
            'charm'  => $this->track($wallet, 'charm'),
        ]);
    }

    /** `lifetime_value` is coins: gifts sent in rooms (wealth) or received in rooms (charm). */
    protected function track(Wallet $wallet, string $type): array
    {
        $lifetimeValue = $wallet->levelPoints($type);
        $current = $type === 'wealth' ? $wallet->wealthLevel() : $wallet->charmLevel();
        $next = WealthCharmLevel::nextAfter($type, $lifetimeValue);

        return [
            'level'          => $current?->level,
            'name_en'        => $current?->name_en,
            'badge_url'      => $current?->badge_url,
            // Where the current level starts — with next_level.threshold, the progress bar's two ends.
            'threshold'      => $current?->threshold,
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
