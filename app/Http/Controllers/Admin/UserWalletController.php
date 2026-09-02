<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Models\CoinTransaction;
use App\Models\DiamondTransaction;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A.3d — wallet view, ledger and manual adjustments. docs/03 §10.
 *
 * Credit and debit are separate permissions and separate routes on purpose: being trusted
 * to hand a user coins is not the same as being trusted to take them away.
 */
class UserWalletController extends Controller
{
    public function __construct(protected WalletService $wallets)
    {
    }

    /** GET /admin/users/{user}/wallet. */
    public function show(User $user): JsonResponse
    {
        $wallet = $this->wallets->forUser($user);

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
            'version'                  => $wallet->version,
        ]);
    }

    /** GET /admin/users/{user}/transactions — the ledger, newest first. */
    public function transactions(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['sometimes', Rule::in([Wallet::COIN, Wallet::DIAMOND])],
            'type'     => ['sometimes', 'nullable', 'string', 'max:40'],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $currency = $data['currency'] ?? Wallet::COIN;
        $model = $currency === Wallet::DIAMOND ? DiamondTransaction::class : CoinTransaction::class;

        $paginator = $model::query()
            ->with('performedBy:id,name')
            ->where('user_id', $user->id)
            ->when($data['type'] ?? null, fn ($q, string $type) => $q->where('type', $type))
            ->orderByDesc('id')
            ->paginate(
                perPage: (int) ($data['per_page'] ?? 25),
                page: (int) ($data['page'] ?? 1),
            );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (LedgerTransaction $row) => [
                'uuid'           => $row->uuid,
                'direction'      => $row->direction,
                'amount'         => $row->amount,
                'signed_amount'  => $row->signedAmount(),
                'balance_before' => $row->balance_before,
                'balance_after'  => $row->balance_after,
                'type'           => $row->type,
                'note'           => $row->note,
                'performed_by'   => $row->performedBy?->name,
                'is_adjustment'  => $row->isAdminAdjustment(),
                'created_at'     => $row->created_at?->toIso8601ZuluString(),
            ]
        )->all());
    }

    /** POST /admin/users/{user}/wallet/credit — A.3d. */
    public function credit(Request $request, User $user): JsonResponse
    {
        return $this->adjust($request, $user, LedgerTransaction::CREDIT);
    }

    /** POST /admin/users/{user}/wallet/debit — A.3d. */
    public function debit(Request $request, User $user): JsonResponse
    {
        return $this->adjust($request, $user, LedgerTransaction::DEBIT);
    }

    /** POST /admin/users/{user}/wallet/freeze — GFT-030. */
    public function freeze(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'frozen' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $wallet = $this->wallets->setFrozen($user, (bool) $data['frozen'], $data['reason'], $request->user());

        return ApiResponse::success(
            ['is_frozen' => $wallet->is_frozen],
            $wallet->is_frozen ? 'Wallet frozen' : 'Wallet unfrozen',
        );
    }

    /**
     * GET /admin/users/{user}/wallet/integrity — docs/02 §15 rule 4, on demand.
     *
     * Walks the ledger and proves the chain of balance_before/balance_after still adds up
     * to the wallet. The nightly job is the real safety net; this is for when someone is
     * staring at a balance they do not believe.
     */
    public function integrity(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['sometimes', Rule::in([Wallet::COIN, Wallet::DIAMOND])],
        ]);

        $result = $this->wallets->verifyIntegrity($user, $data['currency'] ?? Wallet::COIN);

        return ApiResponse::success(
            $result,
            $result['ok'] ? 'Ledger and wallet agree' : 'Ledger does not reconcile',
        );
    }

    protected function adjust(Request $request, User $user, string $direction): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['required', Rule::in([Wallet::COIN, Wallet::DIAMOND])],
            // Integers only — docs/02 §15 rule 1. `numeric` would quietly accept 10.5.
            'amount'   => ['required', 'integer', 'min:1', 'max:1000000000'],
            'note'     => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $transaction = $this->wallets->adjust(
            user: $user,
            currency: $data['currency'],
            direction: $direction,
            amount: (int) $data['amount'],
            note: $data['note'],
            actor: $request->user(),
            idempotencyKey: $request->header('X-Idempotency-Key'),
        );

        return ApiResponse::success([
            'ledger_uuid'    => $transaction->uuid,
            'direction'      => $transaction->direction,
            'amount'         => $transaction->amount,
            'balance_before' => $transaction->balance_before,
            'balance_after'  => $transaction->balance_after,
            'currency'       => $data['currency'],
        ], sprintf(
            '%s %s %s',
            $direction === LedgerTransaction::CREDIT ? 'Credited' : 'Debited',
            number_format($transaction->amount),
            $data['currency'] === Wallet::DIAMOND ? 'diamonds' : 'coins',
        ));
    }
}
