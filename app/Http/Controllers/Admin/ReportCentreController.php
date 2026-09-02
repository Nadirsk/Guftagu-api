<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Exceptions\ScopeException;
use App\Domain\Access\Services\PermissionResolver;
use App\Domain\Access\Services\ScopeFilter;
use App\Domain\Reports\ReportEngine;
use App\Http\Controllers\Controller;
use App\Jobs\BuildReportExport;
use App\Models\ReportExport;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GFT-106 / GFT-107 — the report centre (A.10b, A.10c). docs/03 §14.2.
 *
 * Each report type is gated on its own `reports_export.*` permission, so somebody who may
 * pull a user list is not thereby able to pull revenue.
 */
class ReportCentreController extends Controller
{
    /** Report type → the permission that governs it. */
    protected const PERMISSION = [
        'revenue'      => 'reports_export.revenue',
        'users'        => 'reports_export.users',
        'hosts'        => 'reports_export.hosts',
        'transactions' => 'reports_export.transactions',
    ];

    public function __construct(
        protected ReportEngine $engine,
        protected PermissionResolver $permissions,
        protected ScopeFilter $scope,
    ) {
    }

    /** GET /admin/reports — what can be built, and with what. */
    public function index(Request $request): JsonResponse
    {
        $scoped = $this->scope->agencyIds($request->user()) !== null;

        return ApiResponse::success([
            'scope' => $this->scope->describe($request->user()),
            'types' => collect(ReportEngine::TYPES)
                ->reject(fn (string $type) => $scoped && $type === 'revenue')
                ->values()
                ->map(fn (string $type) => [
                'type'       => $type,
                'columns'    => $this->engine->columns($type),
                'permission' => self::PERMISSION[$type],
            ]),
            'filters'         => ReportEngine::FILTERS,
            'preview_limit'   => ReportEngine::PREVIEW_LIMIT,
            'formats'         => ['csv', 'pdf'],
            'pdf_row_cap'     => ReportEngine::PDF_ROW_CAP,
            // CSV streams a row at a time and survives a 200,000-row report; PDF has no
            // streaming equivalent and is capped accordingly.
            'note' => sprintf(
                'CSV streams and handles any size. PDF is laid out in memory, so it is capped at %s rows — beyond that, export as CSV instead.',
                number_format(ReportEngine::PDF_ROW_CAP),
            ),
        ]);
    }

    /**
     * POST /admin/reports/preview — the first hundred rows, plus the true total.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'    => ['required', Rule::in(ReportEngine::TYPES)],
            'filters' => ['sometimes', 'array'],
        ]);

        $this->authorizeType($request, $data['type']);

        $result = $this->engine->preview($data['type'], $this->filtersFor($request, $data['type']));

        return ApiResponse::success($result + [
            'note' => $result['truncated']
                ? sprintf(
                    'Showing the first %d of %s rows. Export to get all of them.',
                    ReportEngine::PREVIEW_LIMIT,
                    number_format($result['total']),
                )
                : null,
        ]);
    }

    /**
     * GET /admin/reports/revenue/reconcile — A.10b, as a check rather than a claim.
     */
    public function reconcile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to'   => ['sometimes', 'date'],
        ]);

        $this->authorizeType($request, 'revenue');

        // Same reasoning as the revenue report itself.
        if ($this->scope->agencyIds($request->user()) !== null) {
            throw new ScopeException(
                'OUT_OF_SCOPE',
                'Platform revenue reconciliation is not available on a scoped account.',
            );
        }

        return ApiResponse::success($this->engine->reconcile($data));
    }

    /**
     * POST /admin/reports/export — queue the build.
     */
    public function export(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'    => ['required', Rule::in(ReportEngine::TYPES)],
            'format'  => ['sometimes', Rule::in(['csv', 'pdf'])],
            'filters' => ['sometimes', 'array'],
        ]);

        $this->authorizeType($request, $data['type']);

        $filters = $this->filtersFor($request, $data['type']);
        $format = $data['format'] ?? 'csv';

        // Validate the filters now, so an unusable filter fails here rather than inside a
        // worker where the operator only sees "failed".
        $total = $this->engine->count($data['type'], $filters);

        // dompdf builds the whole document in memory before it can paginate, so there is
        // no streaming equivalent to the CSV path. Refusing here, before anything is
        // queued, beats a worker that times out or a PDF nobody's viewer can open.
        if ($format === 'pdf' && $total > ReportEngine::PDF_ROW_CAP) {
            return ApiResponse::error(
                'TOO_LARGE_FOR_PDF',
                sprintf(
                    '%s rows is too large to lay out as a PDF (the cap is %s). Export it as CSV instead — that format is built to handle a report this size.',
                    number_format($total),
                    number_format(ReportEngine::PDF_ROW_CAP),
                ),
                null,
                422,
            );
        }

        $export = ReportExport::create([
            'admin_user_id' => $request->user()->id,
            'type'          => $data['type'],
            'format'        => $format,
            // The scope is persisted onto the row, so the worker builds the same report
            // the operator previewed even if their grant changes before it runs.
            'filters'       => $filters,
            'status'        => ReportExport::QUEUED,
        ]);

        BuildReportExport::dispatch($export->id);

        return ApiResponse::success([
            'uuid'   => $export->uuid,
            'status' => $export->status,
        ], 'Export queued — it will appear in your downloads when it is ready', 202);
    }

    /** GET /admin/reports/exports — the download centre. */
    public function exports(Request $request): JsonResponse
    {
        $rows = ReportExport::query()
            ->where('admin_user_id', $request->user()->id)
            ->latest('id')
            ->limit(30)
            ->get();

        return ApiResponse::success($rows->map(fn (ReportExport $export) => [
            'uuid'       => $export->uuid,
            'type'       => $export->type,
            'format'     => $export->format,
            'status'     => $export->status,
            'row_count'  => $export->row_count,
            'error'      => $export->error,
            'filters'    => $export->filters,
            'created_at' => $export->created_at?->toIso8601ZuluString(),
            'expires_at' => $export->expires_at?->toIso8601ZuluString(),
            // The row can say `ready` while the file has aged out from under it.
            'downloadable' => $export->status === ReportExport::READY
                && ($export->expires_at === null || $export->expires_at->isFuture()),
        ]));
    }

    /**
     * GET /admin/reports/exports/{uuid}/download — streamed, never read into memory.
     */
    public function download(Request $request, string $uuid): StreamedResponse|JsonResponse
    {
        $export = ReportExport::where('uuid', $uuid)
            ->where('admin_user_id', $request->user()->id)
            ->firstOrFail();

        if ($export->status !== ReportExport::READY || $export->file_path === null) {
            return ApiResponse::error('NOT_READY', "That export is {$export->status}.", null, 409);
        }

        if ($export->expires_at !== null && $export->expires_at->isPast()) {
            return ApiResponse::error(
                'EXPIRED',
                'That export has expired. Exports of financial and personal data are kept for seven days; run it again.',
                null,
                410,
            );
        }

        if (! Storage::disk('local')->exists($export->file_path)) {
            return ApiResponse::error('FILE_MISSING', 'The file is gone from storage. Run the export again.', null, 410);
        }

        // Streamed rather than `->download()` with file contents: a 200,000-row CSV should
        // not be loaded into PHP's memory just to be handed to the browser.
        return Storage::disk('local')->download(
            $export->file_path,
            "guftagu-{$export->type}-{$export->created_at?->format('Y-m-d')}.{$export->format}",
        );
    }

    /**
     * Build the engine filters for a request: the caller's own, plus the scope read from
     * their grant.
     *
     * B.5a insists the scope filter is applied **server-side**. It is injected here, under
     * a reserved key the caller cannot supply, and `ReportEngine::fromRequest()` strips any
     * attempt to send one — a Manager posting their own `_scope_agencies` would otherwise
     * widen their own export.
     *
     * @return array<string, mixed>
     *
     * @throws ScopeException
     */
    protected function filtersFor(Request $request, string $type): array
    {
        $filters = ReportEngine::fromRequest($request->input('filters', []) ?? []);

        $agencies = $this->scope->agencyIds($request->user());

        if ($agencies === null) {
            return $filters;
        }

        // Revenue is platform-wide: a recharge is paid to Guftagu, not to an agency, so
        // there is no honest way to attribute it. Serving a scoped "revenue" report would
        // mean either leaking the platform total or inventing an attribution.
        if ($type === 'revenue') {
            throw new ScopeException(
                'OUT_OF_SCOPE',
                'Revenue is platform-wide and cannot be attributed to an agency, so it is not available on a scoped account. The hosts report covers what your agencies earned.',
            );
        }

        $filters[ReportEngine::SCOPE_KEY] = $agencies;

        return $filters;
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function authorizeType(Request $request, string $type): void
    {
        $permission = self::PERMISSION[$type] ?? null;

        if ($permission === null) {
            return;
        }

        // Route middleware cannot express "the permission depends on the body", so the
        // per-type check lives here. The route still requires *some* export permission.
        abort_unless(
            $this->permissions->has($request->user(), $permission),
            response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'PERMISSION_DENIED',
                    'message' => "Pulling the {$type} report needs {$permission}.",
                ],
            ], 403),
        );
    }
}
