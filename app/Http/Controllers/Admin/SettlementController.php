<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Services\ScopeFilter;
use App\Domain\Agency\SettlementService;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\PayoutBatch;
use App\Models\Settlement;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * GFT-086 / GFT-087 — the settlement workspace (A.8d). docs/03 §13.3.
 */
class SettlementController extends Controller
{
    public function __construct(
        protected SettlementService $settlements,
        protected ScopeFilter $scope,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status'    => ['sometimes', 'nullable', Rule::in(Settlement::STATUSES)],
            'agency_id' => ['sometimes', 'nullable', 'integer'],
            'period'    => ['sometimes', 'nullable', 'date'],
            'page'      => ['sometimes', 'integer', 'min:1'],
            'per_page'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Settlement::query()
            ->with(['agency:id,name,code', 'raiser:id,name', 'approver:id,name', 'batch:id,batch_number,status'])
            ->when($data['status'] ?? null, fn ($q, string $s) => $q->where('status', $s))
            ->when($data['agency_id'] ?? null, fn ($q, int $a) => $q->where('agency_id', $a))
            ->when($data['period'] ?? null, fn ($q, string $p) => $q->whereDate('period_start', Carbon::parse($p)->startOfMonth()))
            ->orderByDesc('period_start')
            ->orderByDesc('id');

        // Money, so this is the one that matters most: a scoped Manager must not see what
        // another agency is owed.
        $this->scope->applyAgency($query, $request->user(), 'settlements.agency_id');

        $paginator = $query->paginate(
            perPage: (int) ($data['per_page'] ?? 25),
            page: (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (Settlement $s) => $this->payload($s)
        )->all());
    }

    public function show(Request $request, Settlement $settlement): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $settlement->agency_id, 'settlement');

        $settlement->load(['agency:id,name,code', 'raiser:id,name', 'approver:id,name', 'batch']);

        return ApiResponse::success($this->payload($settlement) + [
            'notes' => $settlement->notes,
            'batch' => $settlement->batch === null ? null : [
                'id'           => $settlement->batch->id,
                'batch_number' => $settlement->batch->batch_number,
                'status'       => $settlement->batch->status,
                'count'        => $settlement->batch->count,
                'total_paise'  => $settlement->batch->total_paise,
            ],
        ]);
    }

    /**
     * POST /admin/settlements/generate — build (or rebuild) a period's draft.
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id'    => ['required', 'integer', Rule::exists('agencies', 'id')],
            'period_start' => ['required', 'date'],
            'period_end'   => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $this->scope->guardAgency($request->user(), (int) $data['agency_id'], 'agency');

        $settlement = $this->settlements->generate(
            Agency::findOrFail($data['agency_id']),
            Carbon::parse($data['period_start']),
            Carbon::parse($data['period_end']),
            $request->user(),
        );

        return ApiResponse::success($this->payload($settlement), 'Settlement generated');
    }

    public function raise(Request $request, Settlement $settlement): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $settlement->agency_id, 'settlement');

        $data = $request->validate(['notes' => ['sometimes', 'nullable', 'string', 'max:500']]);

        $this->settlements->raise($settlement, $data['notes'] ?? null, $request->user());

        return ApiResponse::success(['status' => $settlement->fresh()->status], 'Settlement raised');
    }

    public function approve(Request $request, Settlement $settlement): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $settlement->agency_id, 'settlement');

        $this->settlements->approve($settlement, $request->user());

        return ApiResponse::success(['status' => $settlement->fresh()->status], 'Settlement approved');
    }

    public function reject(Request $request, Settlement $settlement): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $settlement->agency_id, 'settlement');

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->settlements->reject($settlement, $data['reason'], $request->user());

        return ApiResponse::success(['status' => $settlement->fresh()->status], 'Settlement rejected');
    }

    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settlement_ids'   => ['required', 'array', 'min:1', 'max:200'],
            'settlement_ids.*' => ['integer', Rule::exists('settlements', 'id')],
        ]);

        // Every member is checked, not just the first — a batch is one payment instruction,
        // and one out-of-scope row in it would move somebody else's money.
        foreach (Settlement::whereIn('id', $data['settlement_ids'])->get() as $candidate) {
            $this->scope->guardAgency($request->user(), $candidate->agency_id, 'settlement');
        }

        $batch = $this->settlements->addToBatch($data['settlement_ids'], $request->user());

        return ApiResponse::success([
            'batch_id'     => $batch->id,
            'batch_number' => $batch->batch_number,
            'count'        => $batch->count,
            'total_paise'  => $batch->total_paise,
        ], 'Batch created');
    }

    public function processBatch(Request $request, PayoutBatch $batch): JsonResponse
    {
        foreach ($batch->settlements as $settlement) {
            $this->scope->guardAgency($request->user(), $settlement->agency_id, 'settlement');
        }

        $result = $this->settlements->processBatch($batch, $request->user());

        return ApiResponse::success([
            'batch_number' => $result['batch']->batch_number,
            'newly_paid'   => $result['newly_paid'],
            'already_paid' => $result['already_paid'],
            'count'        => $result['batch']->count,
            'total_paise'  => $result['total_paise'],
            // Say it plainly: the second run is a no-op, and the total does not move.
            'note' => $result['newly_paid'] === 0
                ? 'Nothing new to pay — every settlement in this batch was already paid. The total is unchanged.'
                : null,
        ], 'Batch processed');
    }

    /** GET /admin/settlements/batches — what has been paid and when. */
    public function batches(Request $request): JsonResponse
    {
        $query = PayoutBatch::query()
            ->where('type', 'agency_settlement')
            ->withCount('settlements')
            ->orderByDesc('id');

        $agencies = $this->scope->agencyIds($request->user());

        if ($agencies !== null) {
            // A batch is visible only when *every* settlement in it is in scope. Showing a
            // batch whose total includes another agency would leak that figure through the
            // total even with the rows hidden.
            $query->whereDoesntHave('settlements', fn ($q) => $q->whereNotIn('agency_id', $agencies ?: [0]))
                ->whereHas('settlements');
        }

        $paginator = $query->paginate(
                perPage: (int) $request->integer('per_page', 25),
                page: (int) $request->integer('page', 1),
            );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(fn (PayoutBatch $b) => [
            'id'           => $b->id,
            'batch_number' => $b->batch_number,
            'status'       => $b->status,
            'count'        => $b->count,
            'total_paise'  => $b->total_paise,
            'processed_at' => $b->processed_at?->toIso8601ZuluString(),
            'created_at'   => $b->created_at?->toIso8601ZuluString(),
        ])->all());
    }

    protected function payload(Settlement $settlement): array
    {
        return [
            'id'                 => $settlement->id,
            'uuid'               => $settlement->uuid,
            'agency'             => $settlement->agency === null ? null : [
                'id' => $settlement->agency->id, 'name' => $settlement->agency->name, 'code' => $settlement->agency->code,
            ],
            'period_start'       => $settlement->period_start->toDateString(),
            'period_end'         => $settlement->period_end->toDateString(),
            'gross_diamonds'     => $settlement->gross_diamonds,
            'gross_paise'        => $settlement->gross_paise,
            'platform_cut_paise' => $settlement->platform_cut_paise,
            'agency_cut_paise'   => $settlement->agency_cut_paise,
            'host_cut_paise'     => $settlement->host_cut_paise,
            'net_payable_paise'  => $settlement->net_payable_paise,
            'host_count'         => $settlement->host_count,
            'status'             => $settlement->status,
            'is_editable'        => $settlement->isEditable(),
            // Asserted at write time, reported at read time — a split that stops adding up
            // should be visible on the screen, not only in a log.
            'splits_balance'     => $settlement->splitsBalance(),
            'rate'               => $settlement->rate_numerator === null ? null : [
                'numerator'   => $settlement->rate_numerator,
                'denominator' => $settlement->rate_denominator,
                'note'        => 'Frozen at generation, so approving later still settles at the period\'s price.',
            ],
            'raised_by'          => $settlement->raiser?->name,
            'approved_by'        => $settlement->approver?->name,
            'approved_at'        => $settlement->approved_at?->toIso8601ZuluString(),
            'paid_at'            => $settlement->paid_at?->toIso8601ZuluString(),
            'batch_id'           => $settlement->batch_id,
        ];
    }
}
