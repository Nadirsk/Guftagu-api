<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GFT-109 — audit-log search (A.10d). docs/03 §14.3.
 *
 * Rows have been written since the first module; this is the screen that finally reads
 * them. Append-only: there is deliberately no write endpoint here, and no delete.
 *
 * The list omits `before`/`after` — a permission grant's payload can be large, and a
 * hundred of them would make the list unusable. The detail endpoint carries the diff.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'admin_user_id' => ['sometimes', 'nullable', 'integer'],
            'module'        => ['sometimes', 'nullable', 'string', 'max:50'],
            'action'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'entity_type'   => ['sometimes', 'nullable', 'string', 'max:100'],
            'entity_id'     => ['sometimes', 'nullable', 'string', 'max:100'],
            'q'             => ['sometimes', 'nullable', 'string', 'max:100'],
            'from'          => ['sometimes', 'nullable', 'date'],
            'to'            => ['sometimes', 'nullable', 'date'],
            'source'        => ['sometimes', 'nullable', 'string', 'max:20'],
            'page'          => ['sometimes', 'integer', 'min:1'],
            'per_page'      => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $paginator = $this->filtered($data)
            ->with('adminUser:id,name,email')
            ->latest('id')
            ->paginate(
                perPage: (int) ($data['per_page'] ?? 50),
                page: (int) ($data['page'] ?? 1),
            );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(fn (AuditLog $log) => [
            'id'          => $log->id,
            'actor'       => $log->adminUser?->name ?? 'system',
            'actor_id'    => $log->admin_user_id,
            'action'      => $log->action,
            'module'      => $log->module,
            'entity_type' => $log->entity_type === null ? null : class_basename($log->entity_type),
            'entity_id'   => $log->entity_id,
            'ip'          => $log->ip,
            'request_id'  => $log->request_id,
            'created_at'  => $log->created_at?->toIso8601ZuluString(),
            // Whether the row came from a service that knew what changed, or from the
            // safety-net middleware that only saw a request. Worth knowing before trusting
            // a diff that is not there.
            'source'      => ($log->after['source'] ?? null) === 'middleware' ? 'middleware' : 'service',
            'has_diff'    => $log->before !== null || $log->after !== null,
        ])->all());
    }

    /** GET /admin/audit-logs/{log} — the full before/after. */
    public function show(AuditLog $log): JsonResponse
    {
        $log->load('adminUser:id,name,email');

        return ApiResponse::success([
            'log' => [
                'id'          => $log->id,
                'actor'       => $log->adminUser?->name ?? 'system',
                'actor_email' => $log->adminUser?->email,
                'action'      => $log->action,
                'module'      => $log->module,
                'entity_type' => $log->entity_type,
                'entity_id'   => $log->entity_id,
                'ip'          => $log->ip,
                'user_agent'  => $log->user_agent,
                'request_id'  => $log->request_id,
                'created_at'  => $log->created_at?->toIso8601ZuluString(),
                'before'      => $log->before,
                'after'       => $log->after,
            ],
            'changes' => $this->diff($log),
            // Everything that happened in the same HTTP request. A single ban can produce
            // a sanction row and a wallet freeze; seeing them together is the point.
            'related' => $log->request_id === null ? [] : AuditLog::query()
                ->where('request_id', $log->request_id)
                ->where('id', '!=', $log->id)
                ->orderBy('id')
                ->limit(20)
                ->get()
                ->map(fn (AuditLog $r) => [
                    'id' => $r->id, 'action' => $r->action, 'module' => $r->module,
                ]),
            'source' => ($log->after['source'] ?? null) === 'middleware' ? 'middleware' : 'service',
            'source_note' => ($log->after['source'] ?? null) === 'middleware'
                ? 'Captured by the safety-net middleware, which can only see the request. No before/after diff is available for it — the endpoint should be given explicit logging.'
                : null,
        ]);
    }

    /** GET /admin/audit-logs/filters — what the viewer can filter on. */
    public function filters(): JsonResponse
    {
        return ApiResponse::success([
            'modules' => AuditLog::query()->distinct()->orderBy('module')->pluck('module'),
            'actions' => AuditLog::query()
                ->select('action')
                ->selectRaw('COUNT(*) AS total')
                ->groupBy('action')
                ->orderByDesc('total')
                ->limit(60)
                ->get()
                ->map(fn ($row) => ['action' => $row->action, 'total' => (int) $row->total]),
            'actors' => AdminUser::query()
                ->whereIn('id', AuditLog::query()->select('admin_user_id')->whereNotNull('admin_user_id')->distinct())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (AdminUser $a) => ['id' => $a->id, 'name' => $a->name]),
            'oldest' => AuditLog::query()->min('created_at'),
            'total'  => AuditLog::query()->count(),
        ]);
    }

    /**
     * GET /admin/audit-logs/coverage — how much of the trail is a real diff.
     *
     * A.10d is only satisfied in spirit if the rows are useful. A module showing mostly
     * `middleware` rows has endpoints that mutate without saying what they changed, and
     * this is the screen that makes that visible instead of leaving it to be discovered
     * during an incident.
     */
    public function coverage(Request $request): JsonResponse
    {
        $since = Carbon::parse($request->input('from', now()->subDays(30)));

        $rows = DB::table('audit_logs')
            ->where('created_at', '>=', $since)
            ->selectRaw('module')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN JSON_EXTRACT(after, '$.source') = 'middleware' THEN 1 ELSE 0 END) AS fallback")
            ->groupBy('module')
            ->orderByDesc('total')
            ->get();

        $total = (int) $rows->sum('total');
        $fallback = (int) $rows->sum('fallback');

        return ApiResponse::success([
            'since'    => $since->toDateString(),
            'total'    => $total,
            'fallback' => $fallback,
            'explicit' => $total - $fallback,
            'modules'  => $rows->map(fn ($row) => [
                'module'   => $row->module,
                'total'    => (int) $row->total,
                'fallback' => (int) $row->fallback,
                'explicit' => (int) $row->total - (int) $row->fallback,
            ]),
            'note' => $fallback === 0
                ? 'Every mutation in this window was logged explicitly, with a real before and after.'
                : sprintf(
                    '%s of %s rows came from the safety net rather than from a service, so they carry no diff. Those endpoints are worth giving explicit logging.',
                    number_format($fallback),
                    number_format($total),
                ),
        ]);
    }

    /**
     * Everything that happened to one entity, oldest first — the history of a user, a
     * settlement, a role.
     */
    public function forEntity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type' => ['required', 'string', 'max:100'],
            'entity_id'   => ['required', 'string', 'max:100'],
        ]);

        $rows = AuditLog::query()
            ->with('adminUser:id,name')
            ->where('entity_type', 'like', '%'.$data['entity_type'])
            ->where('entity_id', $data['entity_id'])
            ->orderBy('id')
            ->limit(200)
            ->get();

        return ApiResponse::success($rows->map(fn (AuditLog $log) => [
            'id'         => $log->id,
            'actor'      => $log->adminUser?->name ?? 'system',
            'action'     => $log->action,
            'module'     => $log->module,
            'changes'    => $this->diff($log),
            'created_at' => $log->created_at?->toIso8601ZuluString(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function filtered(array $data): \Illuminate\Database\Eloquent\Builder
    {
        return AuditLog::query()
            ->when($data['admin_user_id'] ?? null, fn ($q, int $a) => $q->where('admin_user_id', $a))
            ->when($data['module'] ?? null, fn ($q, string $m) => $q->where('module', $m))
            ->when($data['action'] ?? null, fn ($q, string $a) => $q->where('action', $a))
            // `entity_type` is stored as a FQCN; matching on the tail lets a caller pass
            // either `User` or `App\Models\User` without knowing which.
            ->when($data['entity_type'] ?? null, fn ($q, string $t) => $q->where('entity_type', 'like', '%'.$t))
            ->when($data['entity_id'] ?? null, fn ($q, string $i) => $q->where('entity_id', $i))
            ->when($data['q'] ?? null, fn ($q, string $term) => $q->where(fn ($w) => $w
                ->where('action', 'like', "%{$term}%")
                ->orWhere('entity_id', $term)
                ->orWhere('ip', $term)
                ->orWhere('request_id', $term)))
            ->when($data['from'] ?? null, fn ($q, string $f) => $q->where('created_at', '>=', Carbon::parse($f)->startOfDay()))
            ->when($data['to'] ?? null, fn ($q, string $t) => $q->where('created_at', '<=', Carbon::parse($t)->endOfDay()))
            ->when(
                ($data['source'] ?? null) === 'middleware',
                fn ($q) => $q->whereRaw("JSON_EXTRACT(after, '$.source') = 'middleware'"),
            )
            ->when(
                ($data['source'] ?? null) === 'service',
                fn ($q) => $q->where(fn ($w) => $w
                    ->whereNull('after')
                    ->orWhereRaw("JSON_EXTRACT(after, '$.source') IS NULL")
                    ->orWhereRaw("JSON_EXTRACT(after, '$.source') != 'middleware'")),
            );
    }

    /**
     * A field-level diff — GFT-113 renders this.
     *
     * Only keys that actually changed appear. A service logs a partial `before` (the fields
     * it was about to touch) and a partial `after`, so a naive union would report every
     * unmentioned key as "set from nothing", which is noise rather than a change.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function diff(AuditLog $log): array
    {
        $before = $log->before ?? [];
        $after = $log->after ?? [];

        unset($after['source']);

        $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
        $changes = [];

        foreach ($keys as $key) {
            $had = array_key_exists($key, $before);
            $has = array_key_exists($key, $after);

            $from = $before[$key] ?? null;
            $to = $after[$key] ?? null;

            if ($had && $has && $this->same($from, $to)) {
                continue;
            }

            $changes[] = [
                'field' => $key,
                'from'  => $had ? $from : null,
                'to'    => $has ? $to : null,
                // Says which half of the record the service actually captured, so a
                // one-sided entry does not read as a deletion.
                'kind'  => match (true) {
                    ! $had => 'set',
                    ! $has => 'recorded_before_only',
                    default => 'changed',
                },
            ];
        }

        return $changes;
    }

    protected function same(mixed $a, mixed $b): bool
    {
        return is_scalar($a) && is_scalar($b) ? (string) $a === (string) $b : $a === $b;
    }
}
