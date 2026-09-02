<?php

namespace App\Jobs;

use App\Domain\Reports\ReportEngine;
use App\Models\ReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * A.2d and A.10c — "a job is queued, I am not blocked, and I receive a download link when
 * it completes", and "a 200,000-row transaction report exports without timing out and the
 * file contains every row."
 *
 * Both of those constrain the *shape* of this job, not just its existence:
 *
 *  - It is genuinely queued. The request returns an id straight away and the panel polls
 *    the row until it turns `ready`.
 *
 *  - **It streams.** Rows arrive from `ReportEngine::stream()`, a `LazyCollection` backed
 *    by keyset pagination, and go straight out through a file handle. Nothing accumulates:
 *    not an array of rows, not an in-memory CSV buffer. The previous version built the
 *    whole file in `php://temp` and would have died somewhere around row 50,000.
 *
 *  - `$timeout` is generous because a large export is legitimately slow, and `$tries` is 1
 *    for the same reason a half-written file must not be retried into a half-written file.
 *    A failure is reported on the row, and the operator runs it again.
 *
 * Files land on the local disk. docs/07 puts them in Spaces with a signed URL in
 * production — the only change there is the disk name.
 */
class BuildReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** 15 minutes. A 200k-row export takes a while; killing it at 60s helps nobody. */
    public int $timeout = 900;

    public function __construct(public int $exportId)
    {
    }

    public function handle(ReportEngine $engine): void
    {
        $export = ReportExport::find($this->exportId);

        if ($export === null || $export->status === ReportExport::READY) {
            return;
        }

        $export->update(['status' => ReportExport::PROCESSING]);

        try {
            if ($export->format === 'pdf') {
                $this->buildPdf($export, $engine);
            } else {
                $this->buildCsv($export, $engine);
            }
        } catch (\Throwable $e) {
            $export->update([
                'status' => ReportExport::FAILED,
                'error'  => str($e->getMessage())->limit(490)->value(),
            ]);

            throw $e;
        }
    }

    protected function buildCsv(ReportExport $export, ReportEngine $engine): void
    {
        $relative = "exports/{$export->uuid}.csv";
        $disk = Storage::disk('local');
        $disk->makeDirectory('exports');
        $absolute = $disk->path($relative);

        $handle = fopen($absolute, 'w');

        if ($handle === false) {
            $export->update(['status' => ReportExport::FAILED, 'error' => 'Could not open the export file for writing.']);

            return;
        }

        try {
            $columns = $engine->columns($export->type);
            $filters = $export->filters ?? [];

            // Excel reads a bare UTF-8 CSV as Windows-1252 and mangles Devanagari. A BOM
            // is the only thing that reliably tells it otherwise, and the platform is
            // bilingual.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            $written = 0;

            foreach ($engine->stream($export->type, $filters) as $row) {
                // Ordered by the column list, not by whatever order the row came in, so a
                // change in the query cannot silently shuffle the file.
                fputcsv($handle, array_map(fn (string $c) => $row[$c] ?? '', $columns));
                $written++;

                // Flush periodically so a long export releases its buffer rather than
                // growing one.
                if ($written % ReportEngine::CHUNK === 0) {
                    fflush($handle);
                }
            }

            fclose($handle);

            $export->update([
                'status'    => ReportExport::READY,
                'file_path' => $relative,
                'row_count' => $written,
                // Exports of financial and personal data should not sit around forever.
                'expires_at' => now()->addDays(7),
            ]);
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            // A partial file is worse than none: somebody would download it and treat the
            // truncated contents as the whole report.
            $disk->delete($relative);

            throw $e;
        }
    }

    /**
     * GFT-107 — the PDF leg.
     *
     * Unlike the CSV path, dompdf builds the whole document in memory before it can
     * paginate — there is no streaming equivalent. `ReportEngine::PDF_ROW_CAP` is enforced
     * by the controller before this job is even queued, so hitting it here would mean the
     * cap changed underneath a queued job; that is treated as a failure with a clear
     * message rather than silently rendering a truncated report.
     */
    protected function buildPdf(ReportExport $export, ReportEngine $engine): void
    {
        $relative = "exports/{$export->uuid}.pdf";
        $disk = Storage::disk('local');
        $disk->makeDirectory('exports');

        $columns = $engine->columns($export->type);
        $filters = $export->filters ?? [];

        $rows = $engine->stream($export->type, $filters)->take(ReportEngine::PDF_ROW_CAP + 1)->all();

        if (count($rows) > ReportEngine::PDF_ROW_CAP) {
            $export->update([
                'status' => ReportExport::FAILED,
                'error'  => sprintf(
                    'This report grew past %s rows while queued, which is too large to lay out as a PDF. Export it as CSV instead.',
                    number_format(ReportEngine::PDF_ROW_CAP),
                ),
            ]);

            return;
        }

        $filterSummary = collect($filters)
            ->reject(fn ($v, $k) => str_starts_with((string) $k, '_'))
            ->map(fn ($v, $k) => "{$k}: {$v}")
            ->implode(', ');

        $pdf = Pdf::loadView('reports.pdf', [
            'type'          => $export->type,
            'columns'       => $columns,
            'rows'          => $rows,
            'generatedAt'   => now()->toDayDateTimeString(),
            'filterSummary' => $filterSummary,
        ])->setPaper('a4', 'landscape');

        $disk->put($relative, $pdf->output());

        $export->update([
            'status'     => ReportExport::READY,
            'file_path'  => $relative,
            'row_count'  => count($rows),
            'expires_at' => now()->addDays(7),
        ]);
    }
}
