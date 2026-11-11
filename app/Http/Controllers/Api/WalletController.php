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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    /**
     * POST /wallet/exchange — spends whole multiples of the rate in diamonds and credits the
     * coins they buy. Both ledger rows go in one transaction; a replayed Idempotency-Key
     * returns the first result instead of exchanging twice.
     */
    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate(['diamonds' => ['required', 'integer', 'min:1', 'max:1000000000']]);

        $rate = max(1, (int) config('guftagu.diamonds_per_coin'));
        $diamonds = (int) $data['diamonds'];
        $coins = intdiv($diamonds, $rate);

        if ($coins < 1) {
            return ApiResponse::error('INVALID_AMOUNT', "Exchange at least {$rate} diamonds for 1 coin.", ['rate' => $rate], 422);
        }

        $spent = $coins * $rate; // any remainder stays in the wallet
        $user = $request->user();
        $key = $request->header('Idempotency-Key');
        $key = $key ? 'exchange:'.$user->id.':'.substr($key, 0, 100) : null;

        $wallet = $this->wallets->forUser($user);
        if ($wallet->is_frozen) {
            return ApiResponse::error('WALLET_FROZEN', 'Your wallet is frozen.', null, 403);
        }

        $wallet = DB::transaction(function () use ($user, $spent, $coins, $key) {
            $this->wallets->debitSystem($user, Wallet::DIAMOND, $spent, 'exchange_to_coin', $key);
            $this->wallets->creditSystem($user, Wallet::COIN, $coins, 'exchange_from_diamond', $key);

            return $this->wallets->forUser($user)->refresh();
        });

        return ApiResponse::success([
            'diamonds_spent'  => $spent,
            'coins_added'     => $coins,
            'rate'            => $rate,
            'coin_balance'    => $wallet->coin_balance,
            'diamond_balance' => $wallet->diamond_balance,
        ], 'Exchanged');
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
            // The history page's Consume / Income switch, its category chips and its date.
            'direction' => ['sometimes', 'nullable', 'in:credit,debit'],
            'types'     => ['sometimes', 'nullable', 'string', 'max:300'],  // comma-separated
            'from'      => ['sometimes', 'nullable', 'date'],
            'to'        => ['sometimes', 'nullable', 'date'],
        ]);

        $beforeId = Cursor::decode($data['cursor'] ?? null);
        $limit = (int) ($data['limit'] ?? 30);

        $query = $model::query()->where('user_id', $request->user()->id);

        if (! empty($data['direction'])) {
            $query->where('direction', $data['direction']);
        }

        if (! empty($data['types'])) {
            $query->whereIn('type', array_filter(array_map('trim', explode(',', $data['types']))));
        }

        if (! empty($data['from'])) {
            $query->where('created_at', '>=', Carbon::parse($data['from'])->startOfDay());
        }

        if (! empty($data['to'])) {
            $query->where('created_at', '<=', Carbon::parse($data['to'])->endOfDay());
        }

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
