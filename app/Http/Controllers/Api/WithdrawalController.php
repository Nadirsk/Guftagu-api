<?php

namespace App\Http\Controllers\Api;

use App\Domain\Economy\RateResolver;
use App\Domain\Economy\WithdrawalService;
use App\Domain\Settings\SettingsRepository;
use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * D.6d — diamond-to-cash withdrawal requests. `WithdrawalService::request()` already
 * existed for exactly this ("the mobile app will call this once D.6d exists") — only the
 * route was missing.
 */
class WithdrawalController extends Controller
{
    public function __construct(
        protected WithdrawalService $withdrawals,
        protected SettingsRepository $settings,
        protected RateResolver $rates,
    ) {
    }

    /** GET /withdrawals/config — ⚠ CI-03 supplies the real policy. */
    public function config(): JsonResponse
    {
        $rate = $this->rates->at(RateResolver::DIAMOND_TO_INR);

        return ApiResponse::success([
            'minimum_diamonds'      => $this->settings->int('economy.withdrawal_minimum_diamonds', 0),
            'super_approval_paise'  => $this->settings->int('economy.withdrawal_super_approval_paise', 0),
            'rate' => $rate === null ? null : [
                'numerator'   => $rate->rate_numerator,
                'denominator' => $rate->rate_denominator,
            ],
        ]);
    }

    /** POST /withdrawals — `{diamonds, method?}`. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'diamonds' => ['required', 'integer', 'min:1'],
            'method'   => ['sometimes', 'string', 'max:20'],
        ]);

        $withdrawal = $this->withdrawals->request($request->user(), (int) $data['diamonds'], $data['method'] ?? 'upi');

        return ApiResponse::success($this->payload($withdrawal), 'Withdrawal requested', 201);
    }

    /** GET /withdrawals — the caller's own history. */
    public function index(Request $request): JsonResponse
    {
        $rows = Withdrawal::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 20));

        return ApiResponse::paginated($rows, collect($rows->items())->map(fn (Withdrawal $w) => $this->payload($w))->all());
    }

    protected function payload(Withdrawal $w): array
    {
        return [
            'uuid'         => $w->uuid,
            'diamonds'     => $w->diamonds,
            'gross_paise'  => $w->gross_paise,
            'net_paise'    => $w->net_paise,
            'method'       => $w->method,
            'status'       => $w->status,
            'requested_at' => $w->requested_at?->toIso8601ZuluString(),
            'paid_at'      => $w->paid_at?->toIso8601ZuluString(),
            'rejection_reason' => $w->rejection_reason,
        ];
    }
}
