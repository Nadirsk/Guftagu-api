<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Economy\EconomyException;
use App\Domain\Economy\RateResolver;
use App\Domain\Economy\Reconciler;
use App\Http\Controllers\Controller;
use App\Models\CoinTransaction;
use App\Models\CommissionSlab;
use App\Models\ConversionRate;
use App\Models\DiamondTransaction;
use App\Models\LedgerTransaction;
use App\Models\RechargePackage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Epic A.7 — economy configuration, the ledger and reconciliation. docs/03 §11.
 *
 * ⚠ CI-01 (pricing, packages, rates), CI-02 (commission) and CI-03 (withdrawal policy) are
 * client inputs the SoW does not contain. Everything here is configurable at runtime for
 * exactly that reason — no code changes when the real numbers arrive.
 */
class EconomyController extends Controller
{
    public function __construct(
        protected RateResolver $rates,
        protected Reconciler $reconciler,
        protected AuditLogger $audit,
    ) {
    }

    // ------------------------------------------------------------------ rates

    /** GET /admin/economy/rates — A.7a, with the full effective-date timeline. */
    public function rates(): JsonResponse
    {
        $keys = [RateResolver::COIN_TO_DIAMOND, RateResolver::DIAMOND_TO_INR];
        $payload = [];

        foreach ($keys as $key) {
            $current = $this->rates->at($key);

            $payload[$key] = [
                'current' => $current === null ? null : $this->ratePayload($current),
                'history' => $this->rates->timeline($key)->map(fn (ConversionRate $r) => $this->ratePayload($r)),
            ];
        }

        return ApiResponse::success([
            'rates' => $payload,
            'note'  => 'Rates are stored as a fraction, not a decimal — 1/2 is exact where 0.5 is only exact by luck. Changing one supersedes the current row rather than editing it, so past requests keep the rate they were priced at.',
        ]);
    }

    /** PATCH /admin/economy/rates — supersedes the row in force. */
    public function setRate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'              => ['required', Rule::in([RateResolver::COIN_TO_DIAMOND, RateResolver::DIAMOND_TO_INR])],
            'rate_numerator'   => ['required', 'integer', 'min:1', 'max:1000000000'],
            'rate_denominator' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'effective_from'   => ['sometimes', 'nullable', 'date'],
            'note'             => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $previous = $this->rates->at($data['key']);

        $rate = $this->rates->set(
            key: $data['key'],
            numerator: (int) $data['rate_numerator'],
            denominator: (int) $data['rate_denominator'],
            from: isset($data['effective_from']) ? Carbon::parse($data['effective_from']) : null,
            setBy: $request->user()->id,
            note: $data['note'] ?? null,
        );

        $this->audit->log(
            $request->user(),
            'economy.rate_set',
            'economy',
            ConversionRate::class,
            $rate->id,
            $previous === null ? null : ['rate' => "{$previous->rate_numerator}/{$previous->rate_denominator}"],
            ['rate' => "{$rate->rate_numerator}/{$rate->rate_denominator}", 'from' => $rate->effective_from->toIso8601ZuluString()],
        );

        return ApiResponse::success($this->ratePayload($rate), 'Rate updated — requests already raised keep their original rate');
    }

    // --------------------------------------------------------------- packages

    public function packages(Request $request): JsonResponse
    {
        $packages = RechargePackage::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->sellable())
            ->orderBy('sort_order')->orderBy('price_paise')
            ->get();

        return ApiResponse::success($packages->map(fn (RechargePackage $p) => [
            'id'                     => $p->id,
            'name'                   => $p->name,
            'coins'                  => $p->coins,
            'bonus_coins'            => $p->bonus_coins,
            'total_coins'            => $p->totalCoins(),
            'price_paise'            => $p->price_paise,
            'price_rupees'           => $p->price_paise / 100,
            'paise_per_coin'         => $p->paisePerCoin(),
            'is_first_purchase_only' => $p->is_first_purchase_only,
            'badge_text'             => $p->badge_text,
            'is_active'              => $p->is_active,
            'sort_order'             => $p->sort_order,
            'valid_from'             => $p->valid_from?->toIso8601ZuluString(),
            'valid_to'               => $p->valid_to?->toIso8601ZuluString(),
        ])->all());
    }

    public function storePackage(Request $request): JsonResponse
    {
        $data = $this->validatePackage($request);

        $package = RechargePackage::create($data);

        $this->audit->log($request->user(), 'economy.package_create', 'economy', RechargePackage::class, $package->id, null, $data);

        return ApiResponse::success(['id' => $package->id], 'Package created', 201);
    }

    public function updatePackage(Request $request, RechargePackage $package): JsonResponse
    {
        $data = $this->validatePackage($request, false);

        $before = $package->only(array_keys($data));
        $package->fill($data)->save();

        $this->audit->log($request->user(), 'economy.package_update', 'economy', RechargePackage::class, $package->id, $before, $data);

        return ApiResponse::success(null, 'Package updated');
    }

    // ----------------------------------------------------------------- slabs

    public function slabs(Request $request): JsonResponse
    {
        $slabs = CommissionSlab::query()
            ->with('createdBy:id,name')
            ->when($request->input('applies_to'), fn ($q, string $a) => $q->where('applies_to', $a))
            ->orderBy('applies_to')->orderBy('metric')->orderBy('min_value')
            ->get();

        return ApiResponse::success([
            'slabs' => $slabs->map(fn (CommissionSlab $s) => [
                'id'             => $s->id,
                'applies_to'     => $s->applies_to,
                'agency_id'      => $s->agency_id,
                'metric'         => $s->metric,
                'min_value'      => $s->min_value,
                'max_value'      => $s->max_value,
                'percentage_bp'  => $s->percentage_bp,
                'percent'        => $s->percent(),
                'effective_from' => $s->effective_from?->toIso8601ZuluString(),
                'effective_to'   => $s->effective_to?->toIso8601ZuluString(),
                'created_by'     => $s->createdBy?->name,
            ]),
            'applies_to' => CommissionSlab::APPLIES_TO,
            'metrics'    => CommissionSlab::METRICS,
            'note'       => 'Rates are integer basis points — 1250 is 12.50%. A float percentage loses a rupee per thousand transactions and cannot be explained afterwards.',
        ]);
    }

    /**
     * POST /admin/economy/commission-slabs — A.7c.
     *
     * Overlapping slabs are refused with the ranges named, because "commission was wrong
     * for amounts between 5,000 and 10,000" is a bug nobody finds for months.
     */
    public function storeSlab(Request $request): JsonResponse
    {
        $data = $request->validate([
            'applies_to'     => ['required', Rule::in(CommissionSlab::APPLIES_TO)],
            'agency_id'      => ['sometimes', 'nullable', 'integer'],
            'metric'         => ['required', Rule::in(CommissionSlab::METRICS)],
            'min_value'      => ['required', 'integer', 'min:0'],
            'max_value'      => ['sometimes', 'nullable', 'integer', 'gt:min_value'],
            'percentage_bp'  => ['required', 'integer', 'min:0', 'max:10000'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
        ]);

        $from = isset($data['effective_from']) ? Carbon::parse($data['effective_from']) : now();
        $min = (int) $data['min_value'];
        $max = $data['max_value'] ?? null;

        $overlaps = $this->findOverlaps($data['applies_to'], $data['metric'], $data['agency_id'] ?? null, $min, $max, $from);

        if ($overlaps !== []) {
            throw new EconomyException(
                'VALIDATION_ERROR',
                'That range overlaps a slab already in force.',
                422,
                ['overlapping' => $overlaps],
            );
        }

        $slab = CommissionSlab::create([
            ...$data,
            'effective_from' => $from,
            'created_by'     => $request->user()->id,
        ]);

        $this->audit->log($request->user(), 'economy.slab_create', 'economy', CommissionSlab::class, $slab->id, null, $data);

        return ApiResponse::success(['id' => $slab->id], 'Commission slab created', 201);
    }

    public function destroySlab(Request $request, CommissionSlab $slab): JsonResponse
    {
        // Closed rather than deleted: past settlements were computed with it, and the
        // history has to stay explicable.
        $slab->forceFill(['effective_to' => now()])->save();

        $this->audit->log($request->user(), 'economy.slab_close', 'economy', CommissionSlab::class, $slab->id, null, ['effective_to' => now()->toIso8601ZuluString()]);

        return ApiResponse::success(null, 'Slab closed — past settlements keep referencing it');
    }

    // ---------------------------------------------------------------- ledger

    /** GET /admin/economy/ledger — GFT-072, both currencies in one place. */
    public function ledger(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['sometimes', Rule::in(['coin', 'diamond'])],
            'type'     => ['sometimes', 'nullable', 'string', 'max:40'],
            'user_id'  => ['sometimes', 'nullable', 'integer'],
            'from'     => ['sometimes', 'nullable', 'date'],
            'to'       => ['sometimes', 'nullable', 'date'],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $currency = $data['currency'] ?? 'coin';
        $model = $currency === 'diamond' ? DiamondTransaction::class : CoinTransaction::class;

        $paginator = $model::query()
            ->with(['user:id,guftagu_id', 'performedBy:id,name'])
            ->when($data['type'] ?? null, fn ($q, string $t) => $q->where('type', $t))
            ->when($data['user_id'] ?? null, fn ($q, int $u) => $q->where('user_id', $u))
            ->when($data['from'] ?? null, fn ($q, string $f) => $q->where('created_at', '>=', Carbon::parse($f)->startOfDay()))
            ->when($data['to'] ?? null, fn ($q, string $t) => $q->where('created_at', '<=', Carbon::parse($t)->endOfDay()))
            ->orderByDesc('id')
            ->paginate(
                perPage: (int) ($data['per_page'] ?? 50),
                page: (int) ($data['page'] ?? 1),
            );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (LedgerTransaction $row) => [
                'uuid'           => $row->uuid,
                'user'           => $row->user === null ? null : ['id' => $row->user->id, 'guftagu_id' => $row->user->guftagu_id],
                'direction'      => $row->direction,
                'amount'         => $row->amount,
                'signed_amount'  => $row->signedAmount(),
                'balance_before' => $row->balance_before,
                'balance_after'  => $row->balance_after,
                'type'           => $row->type,
                'reference_type' => $row->reference_type,
                'reference_id'   => $row->reference_id,
                'note'           => $row->note,
                'performed_by'   => $row->performedBy?->name,
                'created_at'     => $row->created_at?->toIso8601ZuluString(),
            ]
        )->all());
    }

    // -------------------------------------------------------- reconciliation

    /** GET /admin/economy/reconciliation — A.7d. */
    public function reconciliation(): JsonResponse
    {
        $report = $this->reconciler->run();

        return ApiResponse::success(
            $report,
            $report['ok'] ? 'Every wallet agrees with its ledger' : 'Discrepancies found',
        );
    }

    /** POST /admin/economy/reconciliation/run — the same check, recorded as a deliberate act. */
    public function runReconciliation(Request $request): JsonResponse
    {
        $report = $this->reconciler->run();

        $this->audit->log(
            $request->user(),
            'economy.reconcile',
            'economy',
            null,
            null,
            null,
            ['ok' => $report['ok'], 'mismatches' => array_sum(array_map(
                fn (array $c) => count($c['mismatches']),
                $report['currencies'],
            ))],
        );

        return ApiResponse::success($report, $report['ok'] ? 'Reconciled cleanly' : 'Discrepancies found');
    }

    // ---------------------------------------------------------------- shared

    /** @return array<int, array<string, mixed>> */
    protected function findOverlaps(string $appliesTo, string $metric, ?int $agencyId, int $min, ?int $max, Carbon $from): array
    {
        $candidates = CommissionSlab::query()
            ->where('applies_to', $appliesTo)
            ->where('metric', $metric)
            ->when($agencyId === null, fn ($q) => $q->whereNull('agency_id'), fn ($q) => $q->where('agency_id', $agencyId))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $from))
            ->get();

        $overlaps = [];

        foreach ($candidates as $slab) {
            // Two ranges overlap unless one ends before the other starts. NULL max means
            // unbounded, which overlaps everything above its floor.
            $existingMax = $slab->max_value ?? PHP_INT_MAX;
            $newMax = $max ?? PHP_INT_MAX;

            if ($min <= $existingMax && $slab->min_value <= $newMax) {
                $overlaps[] = [
                    'id'        => $slab->id,
                    'min_value' => $slab->min_value,
                    'max_value' => $slab->max_value,
                    'percent'   => $slab->percent(),
                ];
            }
        }

        return $overlaps;
    }

    /** @return array<string, mixed> */
    protected function validatePackage(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name'                   => [$required, 'string', 'max:80'],
            'coins'                  => [$required, 'integer', 'min:1', 'max:1000000000'],
            'bonus_coins'            => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            // Paise, integer. ₹99 is 9900.
            'price_paise'            => [$required, 'integer', 'min:1', 'max:10000000000'],
            'is_first_purchase_only' => ['sometimes', 'boolean'],
            'is_active'              => ['sometimes', 'boolean'],
            'sort_order'             => ['sometimes', 'integer', 'min:0', 'max:999'],
            'badge_text'             => ['sometimes', 'nullable', 'string', 'max:40'],
            'valid_from'             => ['sometimes', 'nullable', 'date'],
            'valid_to'               => ['sometimes', 'nullable', 'date', 'after:valid_from'],
        ]);
    }

    protected function ratePayload(ConversionRate $rate): array
    {
        return [
            'id'               => $rate->id,
            'key'              => $rate->key,
            'rate_numerator'   => $rate->rate_numerator,
            'rate_denominator' => $rate->rate_denominator,
            'as_decimal'       => $rate->decimalValue(),
            'display'          => "{$rate->rate_numerator} / {$rate->rate_denominator}",
            'effective_from'   => $rate->effective_from?->toIso8601ZuluString(),
            'effective_to'     => $rate->effective_to?->toIso8601ZuluString(),
            'in_force'         => $rate->isInForce(),
            'set_by'           => $rate->setBy?->name,
            'note'             => $rate->note,
        ];
    }
}
