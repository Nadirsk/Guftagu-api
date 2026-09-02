<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Exceptions\ScopeException;
use App\Domain\Access\Services\ScopeFilter;
use App\Domain\Analytics\DashboardService;
use App\Domain\Analytics\ScopedDashboard;
use App\Http\Controllers\Controller;
use App\Jobs\BuildReportExport;
use App\Models\ReportExport;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Epic A.2 — dashboard and analytics. docs/03 §10.
 */
class DashboardController extends Controller
{
    /** A range longer than this is a report, not a dashboard, and should be exported. */
    protected const MAX_RANGE_DAYS = 400;

    public function __construct(
        protected DashboardService $dashboard,
        protected ScopedDashboard $scoped,
        protected ScopeFilter $scope,
    ) {
    }

    /** GET /admin/dashboard/kpis — A.2a. */
    public function kpis(Request $request): JsonResponse
    {
        $agencies = $this->scope->agencyIds($request->user());

        // B.1a: "only agency 12's hosts, rooms and revenue appear". The platform payload is
        // not narrowed — it is replaced, because daily_stats has no agency dimension to
        // narrow by. See ScopedDashboard.
        if ($agencies !== null) {
            [$from, $to] = $this->range($request);

            return ApiResponse::success($this->scoped->kpis($agencies, $from, $to));
        }

        return ApiResponse::success($this->dashboard->kpis());
    }

    /** GET /admin/dashboard/revenue — A.2b. */
    public function revenue(Request $request): JsonResponse
    {
        $this->refusePlatformSeries($request);

        [$from, $to, $granularity] = $this->range($request);

        return ApiResponse::success($this->dashboard->revenue($from, $to, $granularity));
    }

    /** GET /admin/dashboard/engagement — A.2c. */
    public function engagement(Request $request): JsonResponse
    {
        $this->refusePlatformSeries($request);

        [$from, $to, $granularity] = $this->range($request);

        return ApiResponse::success($this->dashboard->engagement($from, $to, $granularity));
    }

    /**
     * POST /admin/dashboard/export — A.2d.
     *
     * Returns immediately with a row to poll. The file is built by a worker.
     */
    public function export(Request $request): JsonResponse
    {
        $this->refusePlatformSeries($request);

        $data = $request->validate([
            'type'   => ['required', Rule::in(['revenue'])],
            'format' => ['sometimes', Rule::in(['csv'])],
            'from'   => ['sometimes', 'date'],
            'to'     => ['sometimes', 'date'],
        ]);

        [$from, $to] = $this->range($request);

        $export = ReportExport::create([
            'admin_user_id' => $request->user()->id,
            'type'          => $data['type'],
            'format'        => $data['format'] ?? 'csv',
            'filters'       => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'status'        => ReportExport::QUEUED,
        ]);

        BuildReportExport::dispatch($export->id);

        return ApiResponse::success([
            'uuid'   => $export->uuid,
            'status' => $export->status,
        ], 'Export queued — it will appear in your downloads when it is ready', 202);
    }

    /** GET /admin/dashboard/exports — the download centre (GFT-022). */
    public function exports(Request $request): JsonResponse
    {
        $rows = ReportExport::query()
            ->where('admin_user_id', $request->user()->id)
            ->latest('id')
            ->limit(20)
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
        ])->all());
    }

    /**
     * GET /admin/dashboard/exports/{export}/download.
     *
     * Scoped to the requesting admin: an export can contain a whole month of financial
     * data, so holding the uuid of someone else's is not authorisation to read it.
     */
    public function download(Request $request, ReportExport $export): StreamedResponse|JsonResponse
    {
        if ($export->admin_user_id !== $request->user()->id) {
            return ApiResponse::error('FORBIDDEN', 'That export belongs to another admin.', null, 403);
        }

        if (! $export->isReady()) {
            return ApiResponse::error(
                'BAD_REQUEST',
                'That export is not ready yet.',
                ['status' => $export->status],
                400,
            );
        }

        if (! Storage::disk('local')->exists($export->file_path)) {
            return ApiResponse::error('NOT_FOUND', 'That file has been cleaned up.', null, 404);
        }

        return Storage::disk('local')->download(
            $export->file_path,
            "guftagu-{$export->type}-{$export->filters['from']}-to-{$export->filters['to']}.csv",
        );
    }

    /**
     * Shared range parsing. Defaults to the last 30 days, clamps anything absurd, and
     * swaps reversed dates rather than returning an empty series that looks like no data.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    protected function range(Request $request): array
    {
        $request->validate([
            'from'        => ['sometimes', 'date'],
            'to'          => ['sometimes', 'date'],
            'granularity' => ['sometimes', Rule::in(['day', 'week', 'month'])],
        ]);

        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : now();
        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))
            : $to->copy()->subDays(29);

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $from = $to->copy()->subDays(self::MAX_RANGE_DAYS);
        }

        return [$from->startOfDay(), $to->endOfDay(), $request->input('granularity', 'day')];
    }

    /**
     * Platform time-series come from `daily_stats`, which records no agency, so there is
     * nothing to filter on.
     *
     * Refusing is the honest option. Returning the platform numbers would leak them, and
     * returning zeroes would look like an outage rather than a boundary.
     *
     * @throws ScopeException
     */
    protected function refusePlatformSeries(Request $request): void
    {
        if ($this->scope->agencyIds($request->user()) === null) {
            return;
        }

        throw new ScopeException(
            'OUT_OF_SCOPE',
            'Platform revenue and engagement are not attributable to an agency, so they are not available on a scoped account. Your dashboard shows your agencies\' hosts, earnings and settlements instead.',
        );
    }
}
