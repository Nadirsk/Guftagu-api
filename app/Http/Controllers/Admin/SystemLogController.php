<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FrontendErrorLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * IT Admin epic — behind `system.logs_view` AND `role:it_admin` (routes/api.php). The
 * role gate is deliberate: Super Admin's blanket permission bypass must not extend to
 * this screen, so it is restricted to the actual IT Admin login, not "whoever holds the
 * permission." Two sources: the Laravel log file on disk, and browser errors the admin
 * panel reports about itself (`frontendStore`, which is deliberately not behind either
 * gate — it is self-reporting, not a read).
 */
class SystemLogController extends Controller
{
    /**
     * How much of the tail of laravel.log to read from disk. The file is append-only and
     * can grow unbounded, so this is a bounded read from the end rather than loading the
     * whole thing — 2 MB is comfortably more than a normal debugging session needs.
     */
    protected const TAIL_BYTES = 2 * 1024 * 1024;

    /** GET /admin/system/logs/laravel */
    public function laravelLog(Request $request): JsonResponse
    {
        $data = $request->validate([
            'level' => ['sometimes', 'nullable', 'string', Rule::in([
                'EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG',
            ])],
            'q'     => ['sometimes', 'nullable', 'string', 'max:200'],
            'lines' => ['sometimes', 'integer', 'min:10', 'max:2000'],
        ]);

        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return ApiResponse::success(['entries' => [], 'truncated' => false, 'file_size' => 0]);
        }

        [$raw, $truncated] = $this->tail($path, self::TAIL_BYTES);
        $entries = $this->parseEntries($raw);

        if (($data['level'] ?? null) !== null) {
            $entries = array_values(array_filter($entries, fn (array $e) => $e['level'] === $data['level']));
        }

        if (($data['q'] ?? null) !== null) {
            $term = mb_strtolower($data['q']);
            $entries = array_values(array_filter(
                $entries,
                fn (array $e) => str_contains(mb_strtolower($e['message']), $term)
            ));
        }

        // Newest first, then capped — the tail already put recent entries last.
        $entries = array_slice(array_reverse($entries), 0, (int) ($data['lines'] ?? 300));

        return ApiResponse::success([
            'entries'   => $entries,
            'truncated' => $truncated,
            'file_size' => filesize($path),
        ]);
    }

    /** GET /admin/system/logs/frontend */
    public function frontendIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'level'    => ['sometimes', 'nullable', 'string', 'max:20'],
            'q'        => ['sometimes', 'nullable', 'string', 'max:200'],
            'from'     => ['sometimes', 'nullable', 'date'],
            'to'       => ['sometimes', 'nullable', 'date'],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $paginator = FrontendErrorLog::query()
            ->when($data['level'] ?? null, fn ($q, string $l) => $q->where('level', $l))
            ->when($data['q'] ?? null, fn ($q, string $term) => $q->where(fn ($w) => $w
                ->where('message', 'like', "%{$term}%")
                ->orWhere('source_url', 'like', "%{$term}%")))
            ->when($data['from'] ?? null, fn ($q, string $f) => $q->where('created_at', '>=', Carbon::parse($f)->startOfDay()))
            ->when($data['to'] ?? null, fn ($q, string $t) => $q->where('created_at', '<=', Carbon::parse($t)->endOfDay()))
            ->with('adminUser:id,name,email')
            ->latest('id')
            ->paginate(
                perPage: (int) ($data['per_page'] ?? 50),
                page: (int) ($data['page'] ?? 1),
            );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(fn (FrontendErrorLog $log) => [
            'id'         => $log->id,
            'level'      => $log->level,
            'message'    => $log->message,
            'stack'      => $log->stack,
            'source_url' => $log->source_url,
            'user_agent' => $log->user_agent,
            'meta'       => $log->meta,
            'admin'      => $log->adminUser === null ? null : ['id' => $log->adminUser->id, 'name' => $log->adminUser->name],
            'created_at' => $log->created_at?->toIso8601ZuluString(),
        ])->all());
    }

    /**
     * POST /admin/system/logs/frontend — the admin-web error boundary reports here.
     * No permission key: any authenticated admin may report their own browser error,
     * whether or not they can see the collected list back.
     */
    public function frontendStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'level'      => ['sometimes', 'string', Rule::in(['error', 'warning', 'info'])],
            'message'    => ['required', 'string', 'max:2000'],
            'stack'      => ['sometimes', 'nullable', 'string', 'max:8000'],
            'source_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'meta'       => ['sometimes', 'nullable', 'array'],
        ]);

        FrontendErrorLog::create([
            'admin_user_id' => $request->user()->id,
            'level'         => $data['level'] ?? 'error',
            'message'       => $data['message'],
            'stack'         => $data['stack'] ?? null,
            'source_url'    => $data['source_url'] ?? null,
            'user_agent'    => (string) $request->header('User-Agent'),
            'meta'          => $data['meta'] ?? null,
        ]);

        return ApiResponse::success(null, 'Logged', 201);
    }

    /**
     * @return array{0: string, 1: bool} the tail of the file, and whether it was truncated
     */
    protected function tail(string $path, int $maxBytes): array
    {
        $size = filesize($path);
        $truncated = $size > $maxBytes;

        $handle = fopen($path, 'r');

        if ($truncated) {
            fseek($handle, -$maxBytes, SEEK_END);
            fgets($handle); // drop the partial line the seek landed inside
        }

        $contents = stream_get_contents($handle);
        fclose($handle);

        return [$contents, $truncated];
    }

    /**
     * Laravel's single-line formatter writes `[timestamp] env.LEVEL: message`, where
     * `message` itself can be a JSON blob or a stack-trace-carrying exception string that
     * spans many raw lines. Each new timestamped line starts an entry; everything after it
     * until the next one is folded into that entry's `stack`.
     *
     * @return array<int, array{timestamp: string, level: string, message: string, stack: ?string}>
     */
    protected function parseEntries(string $raw): array
    {
        $pattern = '/^\[(?<ts>\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}[^\]]*)\]\s+\S+\.(?<level>[A-Z]+):\s(?<message>.*)$/';

        $entries = [];
        $current = null;

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            if (preg_match($pattern, $line, $m) === 1) {
                if ($current !== null) {
                    $entries[] = $this->finishEntry($current);
                }

                $current = ['timestamp' => $m['ts'], 'level' => $m['level'], 'lines' => [$m['message']]];

                continue;
            }

            if ($current !== null && $line !== '') {
                $current['lines'][] = $line;
            }
        }

        if ($current !== null) {
            $entries[] = $this->finishEntry($current);
        }

        return $entries;
    }

    /**
     * @param  array{timestamp: string, level: string, lines: array<int, string>}  $entry
     * @return array{timestamp: string, level: string, message: string, stack: ?string}
     */
    protected function finishEntry(array $entry): array
    {
        $lines = $entry['lines'];
        $message = array_shift($lines);

        return [
            'timestamp' => $entry['timestamp'],
            'level'     => $entry['level'],
            'message'   => $message,
            'stack'     => $lines === [] ? null : implode("\n", $lines),
        ];
    }
}
