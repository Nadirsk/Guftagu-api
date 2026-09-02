<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Services\ScopeFilter;
use App\Domain\Agency\AgencyService;
use App\Domain\Agency\HostEarningsRollup;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Settlement;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * GFT-080 / GFT-085 — agencies and their performance (A.8a, A.8c). docs/03 §13.
 */
class AgencyController extends Controller
{
    public function __construct(
        protected AgencyService $agencies,
        protected HostEarningsRollup $rollup,
        protected AuditLogger $audit,
        protected ScopeFilter $scope,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'status'   => ['sometimes', 'nullable', Rule::in(Agency::STATUSES)],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Agency::query()
            ->withCount(['hosts as host_count' => fn ($q) => $q->where('status', 'approved')])
            ->with(['owner:id,guftagu_id', 'approver:id,name', 'manager:id,name'])
            ->when($data['q'] ?? null, fn ($q, string $t) => $q->search($t))
            ->when($data['status'] ?? null, fn ($q, string $s) => $q->where('status', $s))
            // Pending first: an application waiting on a human is the thing this screen
            // exists to clear.
            ->orderByRaw("FIELD(status, 'pending', 'approved', 'suspended', 'rejected')")
            ->orderByDesc('id');

        // B.1a: the filter is applied in SQL, so counts and pagination are scoped too —
        // hiding rows in the UI would leave the totals wrong and the API wide open.
        $this->scope->applyAgency($query, $request->user(), 'agencies.id');

        $paginator = $query->paginate(
            perPage: (int) ($data['per_page'] ?? 25),
            page: (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (Agency $a) => $this->rowPayload($a)
        )->all());
    }

    /** GET /admin/agencies/{agency} — detail with the current period's performance. */
    public function show(Request $request, Agency $agency): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to'   => ['sometimes', 'date'],
        ]);

        // The other half of B.1a: a direct call for another agency's id is a 403, not a
        // filtered-away row.
        $this->scope->guardAgency($request->user(), $agency->id, 'agency');

        [$from, $to] = $this->range($data);

        $agency->load(['owner:id,guftagu_id', 'approver:id,name', 'manager:id,name']);

        return ApiResponse::success([
            'agency'      => $this->rowPayload($agency) + [
                'description'   => $agency->description,
                'documents'     => $agency->documents ?? [],
                'contact_phone' => $agency->maskedPhone(),
                'contact_email' => $agency->maskedEmail(),
                'rejection_reason' => $agency->rejection_reason,
            ],
            'period'      => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'performance' => $this->rollup->agencyPerformance($agency, $from, $to),
            'hosts'       => $agency->hosts()->with('user:id,guftagu_id')->active()->limit(50)->get()
                ->map(fn ($h) => [
                    'id'         => $h->id,
                    'guftagu_id' => $h->user?->guftagu_id,
                    'tier'       => $h->tier,
                    'status'     => $h->status,
                    'under_contract' => $h->isUnderContract(),
                ]),
            'settlements' => $agency->settlements()->limit(12)->get()->map(fn (Settlement $s) => [
                'id'                => $s->id,
                'period_start'      => $s->period_start->toDateString(),
                'period_end'        => $s->period_end->toDateString(),
                'net_payable_paise' => $s->net_payable_paise,
                'status'            => $s->status,
            ]),
            // `unique_gifters` is null throughout; say why once rather than in every row.
            'note' => 'Unique gifters and room hours stay unavailable until gift and room-session records land with D.1/D.3.',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // A scoped admin creating an agency would create one outside their own scope and
        // then be unable to open it. Refuse rather than produce that.
        if ($this->scope->agencyIds($request->user()) !== null) {
            throw new \App\Domain\Access\Exceptions\ScopeException(
                'OUT_OF_SCOPE',
                'Your access is limited to specific agencies, so you cannot create a new one — it would fall outside your own scope.',
            );
        }

        $data = $this->validated($request);

        $agency = Agency::create($data + [
            'code'   => Agency::nextCode(),
            'status' => Agency::PENDING,
        ]);

        $this->audit->log($request->user(), 'agency.create', 'agency', Agency::class, $agency->id, null, [
            'name' => $agency->name, 'code' => $agency->code,
        ]);

        return ApiResponse::success($this->rowPayload($agency), 'Agency created', 201);
    }

    public function update(Request $request, Agency $agency): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $agency->id, 'agency');

        $data = $this->validated($request, $agency);
        $before = $agency->only(['name', 'commission_bp', 'managed_by']);

        $agency->fill($data)->save();

        $this->audit->log($request->user(), 'agency.update', 'agency', Agency::class, $agency->id, $before, $data);

        return ApiResponse::success($this->rowPayload($agency->refresh()), 'Agency updated');
    }

    public function approve(Request $request, Agency $agency): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $agency->id, 'agency');

        $this->agencies->approve($agency, $request->user());

        return ApiResponse::success(['status' => $agency->fresh()->status], 'Agency approved');
    }

    public function reject(Request $request, Agency $agency): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $agency->id, 'agency');

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->agencies->reject($agency, $data['reason'], $request->user());

        return ApiResponse::success(['status' => $agency->fresh()->status], 'Agency rejected');
    }

    public function suspend(Request $request, Agency $agency): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $agency->id, 'agency');

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->agencies->suspend($agency, $data['reason'], $request->user());

        return ApiResponse::success([
            'status' => $agency->fresh()->status,
            // Worth stating: an operator suspending an agency usually expects its hosts to
            // stop too, and they deliberately do not.
            'hosts_affected' => false,
            'note' => 'Their hosts keep their contracts and keep earning. Suspend a host individually if that is the intent.',
        ], 'Agency suspended');
    }

    public function reinstate(Request $request, Agency $agency): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $agency->id, 'agency');

        $this->agencies->reinstate($agency, $request->user());

        return ApiResponse::success(['status' => $agency->fresh()->status], 'Agency reinstated');
    }

    /** POST /admin/agencies/{agency}/documents — record an uploaded document. */
    public function addDocument(Request $request, Agency $agency): JsonResponse
    {
        $this->scope->guardAgency($request->user(), $agency->id, 'agency');

        $data = $request->validate([
            'type' => ['required', 'string', 'max:40'],
            'url'  => ['required', 'url', 'max:500'],
        ]);

        $documents = $agency->documents ?? [];
        $documents[] = $data + ['uploaded_at' => now()->toIso8601ZuluString()];

        $agency->forceFill(['documents' => $documents])->save();

        $this->audit->log($request->user(), 'agency.document_add', 'agency', Agency::class, $agency->id, null, $data);

        return ApiResponse::success(['documents' => $documents], 'Document recorded');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Agency $agency = null): array
    {
        return $request->validate([
            'name'          => [$agency === null ? 'required' : 'sometimes', 'string', 'min:2', 'max:120'],
            'owner_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'logo_url'      => ['sometimes', 'nullable', 'url', 'max:500'],
            'description'   => ['sometimes', 'nullable', 'string', 'max:2000'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:120'],
            // Basis points, never a float percentage (docs/02 §15).
            'commission_bp' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'managed_by'    => ['sometimes', 'nullable', 'integer', Rule::exists('admin_users', 'id')],
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    protected function range(array $data): array
    {
        $to = isset($data['to']) ? Carbon::parse($data['to']) : now();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->startOfMonth();

        return [$from->startOfDay(), $to->startOfDay()];
    }

    protected function rowPayload(Agency $agency): array
    {
        return [
            'id'            => $agency->id,
            'uuid'          => $agency->uuid,
            'code'          => $agency->code,
            'name'          => $agency->name,
            'logo_url'      => $agency->logo_url,
            'owner'         => $agency->owner === null ? null : [
                'id' => $agency->owner->id, 'guftagu_id' => $agency->owner->guftagu_id,
            ],
            'commission_bp' => $agency->commission_bp,
            'status'        => $agency->status,
            'is_approved'   => $agency->isApproved(),
            'host_count'    => $agency->host_count ?? $agency->hosts()->active()->count(),
            'document_count' => count($agency->documents ?? []),
            'approved_by'   => $agency->approver?->name,
            'approved_at'   => $agency->approved_at?->toIso8601ZuluString(),
            'managed_by'    => $agency->manager?->name,
            'created_at'    => $agency->created_at?->toIso8601ZuluString(),
        ];
    }
}
