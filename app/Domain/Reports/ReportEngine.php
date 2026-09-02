<?php

namespace App\Domain\Reports;

use App\Models\CoinTransaction;
use App\Models\DiamondTransaction;
use App\Models\HostEarning;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * GFT-106 — the report engine (A.10b, A.10c).
 *
 * One filter grammar for four report types, so a saved filter means the same thing on
 * every screen and in every export.
 *
 * Two properties this is built around:
 *
 *  1. **A.10b — revenue reconciles with the ledger, exactly.** Revenue is computed from
 *     `coin_transactions` directly, not from the `daily_stats` rollup the dashboard reads.
 *     The rollup is a convenience that can drift; a financial report cannot be a
 *     convenience. `reconcile()` compares the two and reports the difference rather than
 *     quietly preferring one.
 *
 *  2. **A.10c — 200,000 rows export without timing out.** Every report exposes `stream()`,
 *     a `LazyCollection` driven by `lazyById`, so the exporter never holds more than a
 *     chunk in memory. `rows()` exists for previews only and is hard-capped.
 */
class ReportEngine
{
    public const TYPES = ['revenue', 'users', 'hosts', 'transactions'];

    /** A preview is for eyeballing, never for counting. The export is the real artefact. */
    public const PREVIEW_LIMIT = 100;

    public const CHUNK = 1000;

    /**
     * GFT-107 — the largest report dompdf is asked to lay out.
     *
     * A.10c's 200,000-row guarantee is a CSV guarantee, made true by streaming a row at a
     * time through a file handle. A PDF has no equivalent: dompdf builds the whole document
     * in memory before it can paginate it, so there is no way to "stream" a PDF the way the
     * CSV path does. Rather than let a huge report hang the worker or produce a PDF nobody
     * can open, a report over this cap is refused for PDF with a message pointing at CSV —
     * the format that actually scales.
     */
    public const PDF_ROW_CAP = 2000;

    /**
     * The shared filter grammar. Anything outside this is rejected rather than ignored —
     * a silently dropped filter produces a report that is wrong in a way nobody can see.
     */
    public const FILTERS = [
        'from', 'to', 'status', 'country', 'type', 'direction',
        'min_amount', 'agency_id', 'user_id',
    ];

    /**
     * B.5a — the scope key the controller injects.
     *
     * Deliberately **not** in `FILTERS`, so a caller cannot supply it themselves: a
     * Manager posting `_scope_agencies: [everything]` would otherwise widen their own
     * export. `guardFilters()` rejects it from user input and only the controller, which
     * reads it from the grant, is allowed to set it.
     */
    public const SCOPE_KEY = '_scope_agencies';

    /**
     * Column headers per type, in order. The export and the preview share them so a CSV
     * never disagrees with the screen it was launched from.
     *
     * @return array<int, string>
     */
    public function columns(string $type): array
    {
        return match ($type) {
            'revenue' => ['date', 'recharges', 'coins_purchased', 'gross_paise', 'refunds_paise', 'net_paise'],
            'users' => ['id', 'guftagu_id', 'display_name', 'status', 'country', 'language', 'kyc_status', 'coin_balance', 'diamond_balance', 'registered_at', 'last_active_at'],
            'hosts' => ['host_id', 'guftagu_id', 'agency', 'date', 'diamonds_earned', 'gross_paise', 'platform_cut_paise', 'agency_cut_paise', 'net_paise'],
            'transactions' => ['id', 'uuid', 'user_id', 'guftagu_id', 'currency', 'direction', 'amount', 'balance_after', 'type', 'note', 'created_at'],
            default => throw new ReportException('UNKNOWN_TYPE', "There is no {$type} report.", 422),
        };
    }

    /**
     * A preview: the first `PREVIEW_LIMIT` rows plus the true total.
     *
     * The total is a separate `COUNT`, not `count($rows)` — otherwise a 200,000-row report
     * would preview as "100 rows" and somebody would believe it.
     *
     * @param  array<string, mixed>  $filters
     * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>, total: int, truncated: bool}
     */
    public function preview(string $type, array $filters): array
    {
        $this->guardFilters($filters);

        $total = $this->count($type, $filters);

        $rows = $this->stream($type, $filters)->take(self::PREVIEW_LIMIT)->all();

        return [
            'columns'   => $this->columns($type),
            'rows'      => array_values($rows),
            'total'     => $total,
            'truncated' => $total > self::PREVIEW_LIMIT,
        ];
    }

    /**
     * Every row, lazily. This is what the exporter iterates.
     *
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function stream(string $type, array $filters): LazyCollection
    {
        $this->guardFilters($filters);

        return match ($type) {
            'revenue'      => $this->revenueStream($filters),
            'users'        => $this->usersStream($filters),
            'hosts'        => $this->hostsStream($filters),
            'transactions' => $this->transactionsStream($filters),
            default        => throw new ReportException('UNKNOWN_TYPE', "There is no {$type} report.", 422),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function count(string $type, array $filters): int
    {
        [$from, $to] = $this->range($filters);

        return match ($type) {
            // Revenue is one row per day, so the count is the span, not a table count.
            'revenue'      => (int) $from->diffInDays($to) + 1,
            'users'        => $this->usersQuery($filters)->count(),
            'hosts'        => $this->hostsQuery($filters)->count(),
            'transactions' => $this->transactionsQuery($filters)->count(),
            default        => throw new ReportException('UNKNOWN_TYPE', "There is no {$type} report.", 422),
        };
    }

    // ----------------------------------------------------------------- revenue

    /**
     * A.10b — revenue straight off the coin ledger, day by day.
     *
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, array<string, mixed>>
     */
    protected function revenueStream(array $filters): LazyCollection
    {
        [$from, $to] = $this->range($filters);

        return LazyCollection::make(function () use ($from, $to) {
            // `recharge` only, matching StatsRollup::CLASSIFY. Counting `purchase` here too
            // would make the report and the dashboard disagree by definition, and
            // reconcile() would report a difference that is not really a drift.
            $daily = DB::table('coin_transactions')
                ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->whereIn('type', ['recharge', 'refund'])
                ->selectRaw('DATE(created_at) AS day')
                ->selectRaw("SUM(CASE WHEN type = 'recharge' AND direction = 'credit' THEN 1 ELSE 0 END) AS recharges")
                ->selectRaw("SUM(CASE WHEN type = 'recharge' AND direction = 'credit' THEN amount ELSE 0 END) AS coins")
                ->selectRaw("SUM(CASE WHEN type = 'refund' THEN amount ELSE 0 END) AS refunded_coins")
                ->groupBy('day')
                ->get()
                ->keyBy('day');

            // Every day in the range appears, including the quiet ones. A gap in a revenue
            // report reads as missing data, not as a day with no sales.
            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $key = $day->toDateString();
                $row = $daily->get($key);

                $coins = (int) ($row->coins ?? 0);
                $refunded = (int) ($row->refunded_coins ?? 0);

                yield [
                    'date'            => $key,
                    'recharges'       => (int) ($row->recharges ?? 0),
                    'coins_purchased' => $coins,
                    'gross_paise'     => $this->coinsToPaise($coins),
                    'refunds_paise'   => $this->coinsToPaise($refunded),
                    'net_paise'       => $this->coinsToPaise($coins - $refunded),
                ];
            }
        });
    }

    /**
     * A.10b, stated as a check: does the report agree with the rollup the dashboard reads?
     *
     * Neither is silently preferred. If they differ, the number to trust is the ledger, and
     * the rollup needs rebuilding — the response says so.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function reconcile(array $filters): array
    {
        [$from, $to] = $this->range($filters);

        $ledgerCoins = (int) DB::table('coin_transactions')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where('type', 'recharge')
            ->where('direction', 'credit')
            ->sum('amount');

        $rollupCoins = (int) DB::table('daily_stats')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('recharge_coins');

        return [
            'period'        => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'ledger_coins'  => $ledgerCoins,
            'rollup_coins'  => $rollupCoins,
            'difference'    => $ledgerCoins - $rollupCoins,
            'matches'       => $ledgerCoins === $rollupCoins,
            'authoritative' => 'ledger',
            'note' => $ledgerCoins === $rollupCoins
                ? 'The report and the dashboard rollup agree for this period.'
                : 'The report is computed from the ledger and is the number to trust. Rebuild the rollup with `php artisan stats:rollup` to bring the dashboard back in line.',
        ];
    }

    // ------------------------------------------------------------------- users

    protected function usersQuery(array $filters): Builder
    {
        [$from, $to] = $this->range($filters);

        return User::query()
            ->with(['profile:id,user_id,display_name,country,language', 'wallet:id,user_id,coin_balance,diamond_balance', 'kyc:user_kyc.id,user_kyc.user_id,user_kyc.status'])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['country'] ?? null), fn ($q) => $q->whereHas('profile', fn ($p) => $p->where('country', $filters['country'])))
            // A scoped admin has no business exporting the whole user base. They get the
            // users who host for their agencies — the population their scope is about.
            ->when(
                $this->scopeAgencies($filters) !== null,
                fn ($q) => $q->whereHas('hostProfile', fn ($h) => $h->whereIn('agency_id', $this->scopeAgencies($filters) ?: [0])),
            );
    }

    protected function usersStream(array $filters): LazyCollection
    {
        return $this->usersQuery($filters)
            ->lazyById(self::CHUNK)
            ->map(fn (User $u) => [
                'id'              => $u->id,
                'guftagu_id'      => $u->guftagu_id,
                'display_name'    => $u->profile?->display_name,
                'status'          => $u->status,
                'country'         => $u->profile?->country,
                'language'        => $u->profile?->language,
                'kyc_status'      => $u->kyc?->status ?? 'none',
                'coin_balance'    => $u->wallet?->coin_balance ?? 0,
                'diamond_balance' => $u->wallet?->diamond_balance ?? 0,
                'registered_at'   => $u->created_at?->toDateTimeString(),
                'last_active_at'  => $u->last_active_at?->toDateTimeString(),
            ]);
    }

    // ------------------------------------------------------------------- hosts

    protected function hostsQuery(array $filters): \Illuminate\Database\Query\Builder
    {
        [$from, $to] = $this->range($filters);

        return DB::table('host_earnings')
            ->join('hosts', 'hosts.id', '=', 'host_earnings.host_id')
            ->join('users', 'users.id', '=', 'hosts.user_id')
            ->leftJoin('agencies', 'agencies.id', '=', 'hosts.agency_id')
            ->whereBetween('host_earnings.date', [$from->toDateString(), $to->toDateString()])
            ->when(filled($filters['agency_id'] ?? null), fn ($q) => $q->where('hosts.agency_id', $filters['agency_id']))
            // B.5a: "the scope filter is applied server-side — not by hiding rows in the
            // UI". An empty allow-list means an empty report, never the whole table.
            ->when(
                $this->scopeAgencies($filters) !== null,
                fn ($q) => $q->whereIn('hosts.agency_id', $this->scopeAgencies($filters) ?: [0]),
            )
            ->select([
                'host_earnings.id AS row_id',
                'host_earnings.host_id',
                'users.guftagu_id',
                'agencies.name AS agency',
                'host_earnings.date',
                'host_earnings.diamonds_earned',
                'host_earnings.gross_paise',
                'host_earnings.platform_cut_paise',
                'host_earnings.agency_cut_paise',
                'host_earnings.net_paise',
            ]);
    }

    protected function hostsStream(array $filters): LazyCollection
    {
        return $this->hostsQuery($filters)
            ->orderBy('host_earnings.id')
            ->lazyById(self::CHUNK, 'host_earnings.id', 'row_id')
            ->map(fn ($row) => [
                'host_id'            => $row->host_id,
                'guftagu_id'         => $row->guftagu_id,
                'agency'             => $row->agency,
                'date'               => $row->date,
                'diamonds_earned'    => $row->diamonds_earned,
                'gross_paise'        => $row->gross_paise,
                'platform_cut_paise' => $row->platform_cut_paise,
                'agency_cut_paise'   => $row->agency_cut_paise,
                'net_paise'          => $row->net_paise,
            ]);
    }

    // ------------------------------------------------------------ transactions

    protected function transactionsQuery(array $filters): Builder
    {
        [$from, $to] = $this->range($filters);

        // Coins and diamonds live in separate tables on purpose (docs/02 §5.2), so the
        // currency filter picks a table rather than adding a WHERE.
        $model = ($filters['type'] ?? 'coin') === 'diamond'
            ? DiamondTransaction::class
            : CoinTransaction::class;

        return $model::query()
            ->with('user:id,guftagu_id')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when(filled($filters['direction'] ?? null), fn ($q) => $q->where('direction', $filters['direction']))
            ->when(filled($filters['user_id'] ?? null), fn ($q) => $q->where('user_id', $filters['user_id']))
            ->when(filled($filters['min_amount'] ?? null), fn ($q) => $q->where('amount', '>=', (int) $filters['min_amount']))
            ->when(
                $this->scopeAgencies($filters) !== null,
                fn ($q) => $q->whereHas('user.hostProfile', fn ($h) => $h->whereIn('agency_id', $this->scopeAgencies($filters) ?: [0])),
            );
    }

    protected function transactionsStream(array $filters): LazyCollection
    {
        $currency = ($filters['type'] ?? 'coin') === 'diamond' ? 'diamond' : 'coin';

        return $this->transactionsQuery($filters)
            ->lazyById(self::CHUNK)
            ->map(fn ($t) => [
                'id'            => $t->id,
                'uuid'          => $t->uuid,
                'user_id'       => $t->user_id,
                'guftagu_id'    => $t->user?->guftagu_id,
                'currency'      => $currency,
                'direction'     => $t->direction,
                'amount'        => $t->amount,
                'balance_after' => $t->balance_after,
                'type'          => $t->type,
                'note'          => $t->note,
                'created_at'    => $t->created_at?->toDateTimeString(),
            ]);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $filters
     *
     * @throws ReportException
     */
    protected function guardFilters(array $filters): void
    {
        $unknown = array_diff(array_keys($filters), array_merge(self::FILTERS, [self::SCOPE_KEY]));

        if ($unknown !== []) {
            throw new ReportException(
                'UNKNOWN_FILTER',
                'Unrecognised filter: '.implode(', ', $unknown).'. A dropped filter would produce a report that is wrong invisibly.',
                422,
            );
        }
    }

    /**
     * Strip anything a caller must not set themselves, before validation.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function fromRequest(array $filters): array
    {
        unset($filters[self::SCOPE_KEY]);

        return $filters;
    }

    /**
     * The agencies a report is limited to, or null for unrestricted.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, int>|null
     */
    protected function scopeAgencies(array $filters): ?array
    {
        $scope = $filters[self::SCOPE_KEY] ?? null;

        return is_array($scope) ? array_map('intval', $scope) : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function range(array $filters): array
    {
        $to = filled($filters['to'] ?? null) ? Carbon::parse($filters['to']) : now();
        $from = filled($filters['from'] ?? null) ? Carbon::parse($filters['from']) : $to->copy()->subDays(29);

        if ($from->gt($to)) {
            throw new ReportException('VALIDATION_ERROR', 'The range ends before it starts.', 422);
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }

    /**
     * Coins to paise at the configured rate.
     *
     * Deliberately not injected through `RateResolver` per row: a report covering a rate
     * change would otherwise mix two prices in one column with nothing saying so. The
     * report reports coins, and the paise column is a convenience at today's rate — which
     * `HostEarningsRollup` does not do, because that one is settling real money.
     */
    protected function coinsToPaise(int $coins): int
    {
        static $paisePerCoin = null;

        if ($paisePerCoin === null) {
            $package = DB::table('recharge_packages')
                ->where('is_active', true)
                ->orderBy('coins')
                ->first(['coins', 'price_paise']);

            $paisePerCoin = $package && $package->coins > 0
                ? $package->price_paise / $package->coins
                : 0;
        }

        return (int) round($coins * $paisePerCoin);
    }
}
