<?php

namespace App\Domain\Analytics;

use App\Models\Agency;
use App\Models\Host;
use App\Models\HostTarget;
use App\Models\Settlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GFT-129 — the Manager dashboard (B.1a).
 *
 * **A separate payload, not a filtered one.** The A.2 dashboard reads `daily_stats`, which
 * has no agency dimension at all — there is no `where` clause that turns platform revenue
 * into agency 12's revenue, because a recharge is paid to Guftagu and not to an agency.
 * Trying to filter it would either leak the platform total or invent an attribution, so a
 * scoped admin gets figures built from the tables that *do* carry an agency: hosts, their
 * earnings, their targets, and the settlements owed.
 *
 * What is genuinely not computable is reported as such rather than as zero. That is the
 * same rule used for `unique_gifters` and `room_hours` elsewhere in this codebase.
 */
class ScopedDashboard
{
    /**
     * @param  array<int, int>  $agencyIds
     * @return array<string, mixed>
     */
    public function kpis(array $agencyIds, Carbon $from, Carbon $to): array
    {
        // Scoped to nothing is a real state — a grant with an empty list, or one that has
        // expired. Zeroes are correct here; an unfiltered query would not be.
        if ($agencyIds === []) {
            $agencyIds = [0];
        }

        $hostIds = Host::query()->whereIn('agency_id', $agencyIds)->pluck('id');

        return [
            'scope' => [
                'agencies' => Agency::query()->whereIn('id', $agencyIds)->get(['id', 'name', 'code'])
                    ->map(fn (Agency $a) => ['id' => $a->id, 'name' => $a->name, 'code' => $a->code]),
            ],
            'period'      => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'hosts'       => $this->hosts($agencyIds),
            'earnings'    => $this->earnings($hostIds->all(), $from, $to),
            'targets'     => $this->targets($hostIds->all()),
            'settlements' => $this->settlements($agencyIds),
            'rooms'       => $this->rooms($agencyIds),
            // B.1b — daily, weekly and monthly, all within scope.
            'periods'     => $this->periods($hostIds->all()),
            // B.1c — what is live right now.
            'live'        => $this->liveActivity(),
            'note' => 'Platform-wide figures — total users, DAU and recharge revenue — are not shown: they cannot be attributed to an agency, so a scoped version of them would be invented rather than measured.',
        ];
    }

    /**
     * @param  array<int, int>  $agencyIds
     * @return array<string, int>
     */
    protected function hosts(array $agencyIds): array
    {
        $rows = Host::query()
            ->whereIn('agency_id', $agencyIds)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $today = now()->toDateString();

        return [
            'total'     => (int) $rows->sum(),
            'approved'  => (int) ($rows['approved'] ?? 0),
            'pending'   => (int) ($rows['pending'] ?? 0),
            'suspended' => (int) ($rows['suspended'] ?? 0),
            // Derived from the contract dates, like everywhere else — a contract that
            // ended yesterday stops counting today with nothing having run.
            'under_contract' => Host::query()
                ->whereIn('agency_id', $agencyIds)
                ->where('status', Host::APPROVED)
                ->where(fn ($q) => $q->whereNull('contract_start')->orWhereDate('contract_start', '<=', $today))
                ->where(fn ($q) => $q->whereNull('contract_end')->orWhereDate('contract_end', '>=', $today))
                ->count(),
        ];
    }

    /**
     * @param  array<int, int>  $hostIds
     * @return array<string, mixed>
     */
    protected function earnings(array $hostIds, Carbon $from, Carbon $to): array
    {
        if ($hostIds === []) {
            return [
                'diamonds' => 0, 'gross_paise' => 0, 'agency_cut_paise' => 0,
                'host_cut_paise' => 0, 'earning_hosts' => 0, 'unpriced' => false,
            ];
        }

        $row = DB::table('host_earnings')
            ->whereIn('host_id', $hostIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(diamonds_earned), 0) AS diamonds')
            ->selectRaw('COALESCE(SUM(gross_paise), 0) AS gross')
            ->selectRaw('COALESCE(SUM(agency_cut_paise), 0) AS agency_cut')
            ->selectRaw('COALESCE(SUM(net_paise), 0) AS host_cut')
            ->selectRaw('COUNT(DISTINCT CASE WHEN diamonds_earned > 0 THEN host_id END) AS earning_hosts')
            ->first();

        return [
            'diamonds'         => (int) $row->diamonds,
            'gross_paise'      => (int) $row->gross,
            'agency_cut_paise' => (int) $row->agency_cut,
            'host_cut_paise'   => (int) $row->host_cut,
            'earning_hosts'    => (int) $row->earning_hosts,
            // Diamonds with no rupees behind them is a missing rate, not a free month.
            'unpriced'         => (int) $row->diamonds > 0 && (int) $row->gross === 0,
        ];
    }

    /**
     * @param  array<int, int>  $hostIds
     * @return array<string, int>
     */
    protected function targets(array $hostIds): array
    {
        if ($hostIds === []) {
            return ['running' => 0, 'achieved' => 0, 'missed' => 0];
        }

        $rows = HostTarget::query()
            ->whereIn('host_id', $hostIds)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'running'  => (int) ($rows[HostTarget::ACTIVE] ?? 0),
            'achieved' => (int) ($rows[HostTarget::ACHIEVED] ?? 0),
            'missed'   => (int) ($rows[HostTarget::MISSED] ?? 0),
        ];
    }

    /**
     * @param  array<int, int>  $agencyIds
     * @return array<string, mixed>
     */
    protected function settlements(array $agencyIds): array
    {
        $rows = Settlement::query()
            ->whereIn('agency_id', $agencyIds)
            ->selectRaw('status, COUNT(*) AS total, COALESCE(SUM(net_payable_paise), 0) AS payable')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $outstanding = $rows
            ->only([Settlement::DRAFT, Settlement::MANAGER_RAISED, Settlement::ADMIN_APPROVED])
            ->sum('payable');

        return [
            'draft'             => (int) ($rows[Settlement::DRAFT]->total ?? 0),
            'raised'            => (int) ($rows[Settlement::MANAGER_RAISED]->total ?? 0),
            'approved'          => (int) ($rows[Settlement::ADMIN_APPROVED]->total ?? 0),
            'paid'              => (int) ($rows[Settlement::PAID]->total ?? 0),
            'outstanding_paise' => (int) $outstanding,
            'paid_paise'        => (int) ($rows[Settlement::PAID]->payable ?? 0),
        ];
    }

    /**
     * Rooms belonging to the scope.
     *
     * `rooms` carries no `agency_id` — the link runs `rooms.owner_id → hosts.user_id →
     * hosts.agency_id`, so it is derived rather than stored. That is correct: a room
     * belongs to whoever opened it, and their agency is a fact about them, not the room.
     *
     * @param  array<int, int>  $agencyIds
     * @return array<string, mixed>
     */
    protected function rooms(array $agencyIds): array
    {
        $ownerIds = Host::query()->whereIn('agency_id', $agencyIds)->pluck('user_id');

        if ($ownerIds->isEmpty()) {
            return ['total' => 0, 'live' => 0, 'note' => null];
        }

        $total = DB::table('rooms')->whereIn('owner_id', $ownerIds)->whereNull('deleted_at')->count();
        $live = DB::table('rooms')->whereIn('owner_id', $ownerIds)->where('status', 'live')->count();

        return [
            'total' => $total,
            'live'  => $live,
            'note'  => 'Counted through room ownership — a room belongs to the agency its host belongs to.',
        ];
    }

    /**
     * B.1b — "daily, weekly and monthly figures are all available within scope".
     *
     * Three windows off the same rollup rather than three different queries with three
     * different definitions of "revenue". Each is a plain sum over `host_earnings`, so the
     * monthly figure is the sum of its days by construction and cannot disagree with them.
     *
     * @param  array<int, int>  $hostIds
     * @return array<string, array<string, int>>
     */
    protected function periods(array $hostIds): array
    {
        if ($hostIds === []) {
            $empty = ['diamonds' => 0, 'agency_cut_paise' => 0, 'host_cut_paise' => 0];

            return ['today' => $empty, 'week' => $empty, 'month' => $empty];
        }

        $windows = [
            'today' => [now()->startOfDay(), now()],
            'week'  => [now()->startOfWeek(), now()],
            'month' => [now()->startOfMonth(), now()],
        ];

        $out = [];

        foreach ($windows as $label => [$from, $to]) {
            $row = DB::table('host_earnings')
                ->whereIn('host_id', $hostIds)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('COALESCE(SUM(diamonds_earned), 0) AS diamonds')
                ->selectRaw('COALESCE(SUM(agency_cut_paise), 0) AS agency_cut')
                ->selectRaw('COALESCE(SUM(net_paise), 0) AS host_cut')
                ->first();

            $out[$label] = [
                'diamonds'         => (int) $row->diamonds,
                'agency_cut_paise' => (int) $row->agency_cut,
                'host_cut_paise'   => (int) $row->host_cut,
            ];
        }

        return $out;
    }

    /**
     * B.1c — "given two live events, then both appear with participant counts".
     *
     * Events and campaigns are platform-wide by nature: a tournament is not owned by an
     * agency. They are shown unscoped and **labelled as such**, because a Manager
     * coordinating promotional activity needs to know what is running, and silently
     * hiding it would be less honest than saying it is not theirs alone.
     *
     * The phase is derived from the clock, exactly as the A.9 event list derives it.
     *
     * @return array<string, mixed>
     */
    protected function liveActivity(): array
    {
        $now = now();

        $events = DB::table('events')
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->whereIn('status', ['scheduled', 'live'])
            ->orderBy('ends_at')
            ->limit(10)
            ->get(['id', 'type', 'title_en', 'starts_at', 'ends_at']);

        $participants = DB::table('event_participants')
            ->whereIn('event_id', $events->pluck('id'))
            ->selectRaw('event_id, COUNT(*) AS total')
            ->groupBy('event_id')
            ->pluck('total', 'event_id');

        $campaigns = DB::table('broadcasts')
            ->whereIn('status', ['scheduled', 'sending'])
            ->orderBy('scheduled_at')
            ->limit(10)
            ->get(['id', 'title', 'scheduled_at', 'status']);

        return [
            'events' => $events->map(fn ($e) => [
                'id'           => $e->id,
                'type'         => $e->type,
                'title'        => $e->title_en,
                'ends_at'      => $e->ends_at,
                'participants' => (int) ($participants[$e->id] ?? 0),
            ]),
            'campaigns' => $campaigns->map(fn ($c) => [
                'id'           => $c->id,
                'title'        => $c->title,
                'status'       => $c->status,
                'scheduled_at' => $c->scheduled_at,
            ]),
            'note' => 'Events and campaigns are platform-wide, not agency-specific, so these are not narrowed by your scope.',
        ];
    }
}
