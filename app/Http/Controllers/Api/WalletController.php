<?php

namespace App\Http\Controllers\Api;

use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Models\CoinTransaction;
use App\Models\DiamondTransaction;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use App\Models\WealthCharmLevel;
use App\Support\ApiResponse;
use App\Support\Cursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** D.6c/e — the caller's own wallet and ledger, the app-facing half of `UserWalletController`. */
class WalletController extends Controller
{
    public function __construct(protected WalletService $wallets)
    {
    }

    /** GET /wallet */
    public function show(Request $request): JsonResponse
    {
        $wallet = $this->wallets->forUser($request->user());

        return ApiResponse::success([
            'coin_balance'             => $wallet->coin_balance,
            'diamond_balance'          => $wallet->diamond_balance,
            'frozen_coins'             => $wallet->frozen_coins,
            'frozen_diamonds'          => $wallet->frozen_diamonds,
            'available_coins'          => $wallet->availableOf(Wallet::COIN),
            'available_diamonds'       => $wallet->availableOf(Wallet::DIAMOND),
            'lifetime_coins_purchased' => $wallet->lifetime_coins_purchased,
            'lifetime_coins_spent'     => $wallet->lifetime_coins_spent,
            'lifetime_diamonds_earned' => $wallet->lifetime_diamonds_earned,
            'is_frozen'                => $wallet->is_frozen,
            'wealth_level'             => $this->levelPayload($wallet->wealthLevel()),
            'charm_level'              => $this->levelPayload($wallet->charmLevel()),
        ]);
    }

    /** GET /wallet/coins/transactions */
    public function coinTransactions(Request $request): JsonResponse
    {
        return $this->transactions($request, CoinTransaction::class);
    }

    /** GET /wallet/diamonds/transactions */
    public function diamondTransactions(Request $request): JsonResponse
    {
        return $this->transactions($request, DiamondTransaction::class);
    }

    /** @param  class-string<LedgerTransaction>  $model */
    protected function transactions(Request $request, string $model): JsonResponse
    {
        $data = $request->validate([
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $beforeId = Cursor::decode($data['cursor'] ?? null);
        $limit = (int) ($data['limit'] ?? 30);

        $query = $model::query()->where('user_id', $request->user()->id);

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $items = $hasMore ? $rows->take($limit) : $rows;

        return ApiResponse::cursor(
            $items->map(fn (LedgerTransaction $t) => [
                'uuid'           => $t->uuid,
                'direction'      => $t->direction,
                'amount'         => $t->amount,
                'signed_amount'  => $t->signedAmount(),
                'balance_before' => $t->balance_before,
                'balance_after'  => $t->balance_after,
                'type'           => $t->type,
                'note'           => $t->note,
                'created_at'     => $t->created_at?->toIso8601ZuluString(),
            ])->all(),
            $hasMore ? Cursor::encode($items->last()->id) : null,
        );
    }

    protected function levelPayload(?WealthCharmLevel $level): ?array
    {
        return $level === null ? null : [
            'level'     => $level->level,
            'name_en'   => $level->name_en,
            'badge_url' => $level->badge_url,
        ];
    }
}
