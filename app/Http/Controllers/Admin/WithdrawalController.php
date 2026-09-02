<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Economy\WithdrawalService;
use App\Domain\Settings\SettingsRepository;
use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GFT-069 / GFT-070 — the withdrawal review queue (A.7b). docs/03 §11.
 *
 * Approve and reject are separate permissions: being trusted to pay someone is not the
 * same as being trusted to refuse them.
 */
class WithdrawalController extends Controller
{
    public function __construct(
        protected WithdrawalService $withdrawals,
        protected SettingsRepository $settings,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status'   => ['sometimes', 'nullable', 'string', 'max:30'],
            'user_id'  => ['sometimes', 'nullable', 'integer'],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = Withdrawal::query()
            ->with(['user.profile:id,user_id,display_name', 'reviewedBy:id,name', 'secondApprovedBy:id,name'])
            ->when($data['status'] ?? null, fn ($q, string $s) => $q->where('status', $s))
            ->when($data['user_id'] ?? null, fn ($q, int $u) => $q->where('user_id', $u))
            // Oldest first: a payout queue is a queue, and the person waiting longest
            // should be dealt with first.
            ->orderBy('requested_at')
            ->paginate(
                perPage: (int) ($data['per_page'] ?? 25),
                page: (int) ($data['page'] ?? 1),
            );

        $threshold = $this->settings->int('economy.withdrawal_super_approval_paise', 0);

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (Withdrawal $w) => $this->payload($w, $threshold)
        )->all());
    }

    /** Totals for the queue header — what is waiting, and how much it is worth. */
    public function summary(): JsonResponse
    {
        $open = Withdrawal::query()->whereIn('status', [Withdrawal::PENDING, Withdrawal::PENDING_SUPER]);

        return ApiResponse::success([
            'pending_count'          => (clone $open)->where('status', Withdrawal::PENDING)->count(),
            'awaiting_super_count'   => (clone $open)->where('status', Withdrawal::PENDING_SUPER)->count(),
            'open_total_paise'       => (int) (clone $open)->sum('net_paise'),
            'approved_today_paise'   => (int) Withdrawal::query()
                ->where('status', Withdrawal::APPROVED)
                ->whereDate('reviewed_at', today())
                ->sum('net_paise'),
            'super_approval_paise'   => $this->settings->int('economy.withdrawal_super_approval_paise', 0),
            'minimum_diamonds'       => $this->settings->int('economy.withdrawal_minimum_diamonds', 0),
        ]);
    }

    public function show(Withdrawal $withdrawal): JsonResponse
    {
        $withdrawal->load(['user.profile:id,user_id,display_name', 'user.kyc', 'reviewedBy:id,name', 'secondApprovedBy:id,name']);

        $threshold = $this->settings->int('economy.withdrawal_super_approval_paise', 0);

        return ApiResponse::success([
            'withdrawal' => $this->payload($withdrawal, $threshold),
            // Whether the person is even eligible to be paid — A.3b makes KYC the gate.
            'kyc' => $withdrawal->user?->kyc === null ? null : [
                'status'      => $withdrawal->user->kyc->status,
                'full_name'   => $withdrawal->user->kyc->full_name,
                'upi_id'      => $withdrawal->user->kyc->upi_id,
                'ifsc'        => $withdrawal->user->kyc->ifsc,
                'reviewed_at' => $withdrawal->user->kyc->reviewed_at?->toIso8601ZuluString(),
            ],
        ]);
    }

    /** POST /admin/withdrawals/{withdrawal}/approve. */
    public function approve(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        $result = $this->withdrawals->approve($withdrawal, $request->user());

        return ApiResponse::success(
            [
                'status'             => $result->status,
                'needs_super_admin'  => $result->status === Withdrawal::PENDING_SUPER,
                'second_approved_by' => $result->secondApprovedBy?->name,
            ],
            $result->status === Withdrawal::PENDING_SUPER
                ? 'Above the high-value threshold — a Super Admin must approve it as well'
                : 'Payout approved',
        );
    }

    /** POST /admin/withdrawals/{withdrawal}/reject. */
    public function reject(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $result = $this->withdrawals->reject($withdrawal, $data['reason'], $request->user());

        return ApiResponse::success([
            'status'            => $result->status,
            'diamonds_returned' => $result->diamonds,
        ], 'Payout rejected — the diamonds are back in the user\'s balance');
    }

    /** PATCH /admin/withdrawals/settings — the thresholds CI-03 will eventually fix. */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'minimum_diamonds'     => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'super_approval_paise' => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
        ]);

        if (array_key_exists('minimum_diamonds', $data)) {
            $this->settings->set('economy.withdrawal_minimum_diamonds', (int) $data['minimum_diamonds'], $request->user()->id);
        }

        if (array_key_exists('super_approval_paise', $data)) {
            $this->settings->set('economy.withdrawal_super_approval_paise', (int) $data['super_approval_paise'], $request->user()->id);
        }

        return ApiResponse::success([
            'minimum_diamonds'     => $this->settings->int('economy.withdrawal_minimum_diamonds', 0),
            'super_approval_paise' => $this->settings->int('economy.withdrawal_super_approval_paise', 0),
        ], 'Withdrawal policy updated');
    }

    protected function payload(Withdrawal $w, int $threshold): array
    {
        return [
            'id'       => $w->id,
            'uuid'     => $w->uuid,
            'user'     => $w->user === null ? null : [
                'id'           => $w->user->id,
                'guftagu_id'   => $w->user->guftagu_id,
                'display_name' => $w->user->profile?->display_name,
            ],
            'diamonds'         => $w->diamonds,
            'gross_paise'      => $w->gross_paise,
            'commission_paise' => $w->commission_paise,
            'tds_paise'        => $w->tds_paise,
            'net_paise'        => $w->net_paise,
            'net_rupees'       => $w->net_paise / 100,
            // The rate as it was when raised — not today's, which is the whole point of A.7a.
            'rate'             => "{$w->rate_numerator}/{$w->rate_denominator}",
            'method'           => $w->method,
            'status'           => $w->status,
            'is_open'          => $w->isOpen(),
            'needs_super_admin' => $threshold > 0 && $w->net_paise >= $threshold,
            'requested_at'     => $w->requested_at?->toIso8601ZuluString(),
            'reviewed_by'      => $w->reviewedBy?->name,
            'reviewed_at'      => $w->reviewed_at?->toIso8601ZuluString(),
            'second_approved_by' => $w->secondApprovedBy?->name,
            'rejection_reason' => $w->rejection_reason,
            'utr'              => $w->utr,
        ];
    }
}
