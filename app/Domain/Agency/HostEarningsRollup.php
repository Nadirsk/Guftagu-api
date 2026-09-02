<?php

namespace App\Domain\Agency;

use App\Domain\Economy\RateResolver;
use App\Domain\Economy\SlabResolver;
use App\Models\Agency;
use App\Models\DiamondTransaction;
use App\Models\Host;
use App\Models\HostEarning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GFT-084 — the nightly `host_earnings` rollup (A.8c).
 *
 * **The ledger is the truth; this table is a convenience.** A.8c asks that the rollup
 * totals equal the sum of the underlying diamond credits for the range *exactly*, so the
 * rollup is a pure function of the ledger and is recomputed, never incremented. Running it
 * twice for the same day produces the same row; running it for a day that has since gained
 * a late credit corrects the row rather than double-counting it.
 *
 * `verify()` is the other half of that promise: it re-derives from the ledger and reports
 * the difference, the same way the wallet integrity check does.
 *
 * **What this cannot yet compute.** `unique_gifters` needs the sender, and the diamond
 * ledger does not record one — that arrives with `gift_transactions` in D.1. It is left
 * null rather than zero, because zero reads as "nobody gifted them", which is false.
 * `room_hours` likewise waits on room session records.
 */
class HostEarningsRollup
{
    /** Ledger types that represent gift income to a host. */
    public const GIFT_TYPES = ['gift_received', 'gift_income', 'room_gift'];

    public function __construct(
        protected RateResolver $rates,
        protected SlabResolver $slabs,
    ) {
    }

    /**
     * Rebuild one day for every approved host who earned anything.
     *
     * `priced` is false when no `diamond_to_inr` rate covered that day. The diamonds are
     * still recorded, but every money column is zero — and a zero that means "we could not
     * price this" has to be distinguishable from a zero that means "they earned nothing",
     * or somebody will read a rate gap as a quiet month.
     *
     * @return array{date: string, hosts: int, diamonds: int, net_paise: int, priced: bool}
     */
    public function forDate(?Carbon $date = null): array
    {
        $day = ($date ?? now()->subDay())->startOfDay();

        // One grouped query rather than a query per host: this runs over every host on the
        // platform, every night.
        $totals = DiamondTransaction::query()
            ->join('hosts', 'hosts.user_id', '=', 'diamond_transactions.user_id')
            ->where('diamond_transactions.direction', DiamondTransaction::CREDIT)
            ->whereIn('diamond_transactions.type', self::GIFT_TYPES)
            ->whereBetween('diamond_transactions.created_at', [$day, $day->copy()->endOfDay()])
            ->groupBy('hosts.id', 'hosts.agency_id')
            ->selectRaw('hosts.id AS host_id, hosts.agency_id')
            ->selectRaw('SUM(diamond_transactions.amount) AS diamonds')
            ->selectRaw('COUNT(*) AS gift_count')
            ->get();

        $rate = $this->rates->at(RateResolver::DIAMOND_TO_INR, $day);
        $hosts = 0;
        $diamonds = 0;
        $net = 0;
        $priced = $rate !== null;

        foreach ($totals as $row) {
            $earned = (int) $row->diamonds;

            // No rate configured for that day means no rupee figure can honestly be
            // stated. Record the diamonds and leave the money columns at zero rather than
            // inventing a conversion.
            $grossPaise = $rate === null ? 0 : $this->rates->convert($earned, $rate);

            $split = $this->split($grossPaise, $earned, $row->agency_id, $day);

            // Same rule for a missing platform slab: the row is recorded, the money is
            // not, and the day is reported as unpriced. Gross is zeroed along with the
            // cuts so that `platform + agency + net == gross` holds on every single row —
            // settlement generation derives the platform share from exactly that identity,
            // and a row carrying a gross with no cuts behind it would break it.
            if ($split['platform_bp'] === null) {
                $priced = false;
                $grossPaise = 0;
            }

            HostEarning::updateOrCreate(
                ['host_id' => $row->host_id, 'date' => $day->toDateString()],
                [
                    'diamonds_earned'    => $earned,
                    'gross_paise'        => $grossPaise,
                    'platform_cut_paise' => $split['platform'],
                    'agency_cut_paise'   => $split['agency'],
                    'net_paise'          => $split['host'],
                    'gift_count'         => (int) $row->gift_count,
                    // Uncountable from this ledger — see the class docblock.
                    'unique_gifters'     => null,
                    'room_hours'         => 0,
                ],
            );

            $hosts++;
            $diamonds += $earned;
            $net += $split['host'];
        }

        return [
            'date'      => $day->toDateString(),
            'hosts'     => $hosts,
            'diamonds'  => $diamonds,
            'net_paise' => $net,
            'priced'    => $priced,
        ];
    }

    /**
     * Split gross three ways, with no rounding leak.
     *
     * Platform and agency are truncated with `intdiv`; **the host absorbs the remainder**.
     * That is a policy choice and it is the right one: the earner should not lose paise to
     * the platform's rounding, and the three cuts must add back to gross exactly or the
     * settlement will not balance.
     *
     * **A missing platform slab is not 0%.** Defaulting it to zero would hand the whole
     * gross to the host, because the host takes the remainder — a silent overpayment that
     * nobody would notice until a settlement was already paid. When no slab covers the
     * value, `platform_bp` comes back null and the caller records the diamonds without
     * pricing them, exactly as it does for a missing conversion rate.
     *
     * @return array{platform: int, agency: int, host: int, platform_bp: int|null, agency_bp: int}
     */
    public function split(int $grossPaise, int $diamonds, ?int $agencyId, ?Carbon $at = null): array
    {
        $platformSlab = $this->slabs->for('platform', 'diamonds_earned', $diamonds, null, $at);
        $platformBp = $platformSlab?->percentage_bp;

        // An agency with no slab falls back to its own negotiated rate, which is a real
        // configured number rather than a guess — so that case is safe to default.
        $agencyBp = $agencyId === null
            ? 0
            : $this->slabs->basisPoints('agency', 'diamonds_earned', $diamonds, $agencyId, $at, $this->agencyDefaultBp($agencyId));

        if ($platformBp === null) {
            return [
                'platform'    => 0,
                'agency'      => 0,
                'host'        => 0,
                'platform_bp' => null,
                'agency_bp'   => $agencyBp,
            ];
        }

        $platform = $this->slabs->apply($grossPaise, $platformBp);
        $agency = $this->slabs->apply($grossPaise, $agencyBp);

        return [
            'platform'    => $platform,
            'agency'      => $agency,
            'host'        => $grossPaise - $platform - $agency,
            'platform_bp' => $platformBp,
            'agency_bp'   => $agencyBp,
        ];
    }

    /**
     * Rebuild a whole range. Used after a rate or slab change, when yesterday's numbers
     * were computed on terms that have since been corrected.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forRange(Carbon $from, Carbon $to): array
    {
        $out = [];

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $out[] = $this->forDate($day->copy());
        }

        return $out;
    }

    /**
     * A.8c — prove the rollup still equals the ledger over a range.
     *
     * @return array{matches: bool, rollup_diamonds: int, ledger_diamonds: int, difference: int, days: int}
     */
    public function verify(Host $host, Carbon $from, Carbon $to): array
    {
        $rollup = (int) HostEarning::query()
            ->where('host_id', $host->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('diamonds_earned');

        $ledger = (int) DiamondTransaction::query()
            ->where('user_id', $host->user_id)
            ->where('direction', DiamondTransaction::CREDIT)
            ->whereIn('type', self::GIFT_TYPES)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->sum('amount');

        return [
            'matches'         => $rollup === $ledger,
            'rollup_diamonds' => $rollup,
            'ledger_diamonds' => $ledger,
            'difference'      => $rollup - $ledger,
            'days'            => (int) $from->diffInDays($to) + 1,
        ];
    }

    /**
     * Totals for a host over a range, straight off the rollup.
     *
     * @return array<string, int|null>
     */
    public function totals(Host $host, Carbon $from, Carbon $to): array
    {
        $row = HostEarning::query()
            ->where('host_id', $host->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(diamonds_earned), 0) AS diamonds')
            ->selectRaw('COALESCE(SUM(gross_paise), 0) AS gross')
            ->selectRaw('COALESCE(SUM(platform_cut_paise), 0) AS platform')
            ->selectRaw('COALESCE(SUM(agency_cut_paise), 0) AS agency')
            ->selectRaw('COALESCE(SUM(net_paise), 0) AS net')
            ->selectRaw('COALESCE(SUM(gift_count), 0) AS gifts')
            ->selectRaw('COALESCE(SUM(room_hours), 0) AS hours')
            // Days with any earnings — what a "days active" target is measured against.
            ->selectRaw('COUNT(CASE WHEN diamonds_earned > 0 THEN 1 END) AS active_days')
            ->first();

        return [
            'diamonds'           => (int) $row->diamonds,
            'gross_paise'        => (int) $row->gross,
            'platform_cut_paise' => (int) $row->platform,
            'agency_cut_paise'   => (int) $row->agency,
            'net_paise'          => (int) $row->net,
            'gift_count'         => (int) $row->gifts,
            'room_hours'         => (int) $row->hours,
            'active_days'        => (int) $row->active_days,
            // Still uncountable — stated as null so a UI shows a dash, not a zero.
            'unique_gifters'     => null,
            // Diamonds with no rupees behind them means no rate covered those days, not a
            // free month. Callers surface this rather than showing a bare zero.
            'unpriced'           => (int) $row->diamonds > 0 && (int) $row->gross === 0,
        ];
    }

    /** An agency's own negotiated rate, used when no slab covers the value. */
    protected function agencyDefaultBp(int $agencyId): int
    {
        return (int) (Agency::query()->whereKey($agencyId)->value('commission_bp') ?? 0);
    }

    /**
     * Agency-level performance over a range (GFT-085).
     *
     * @return array<string, mixed>
     */
    public function agencyPerformance(Agency $agency, Carbon $from, Carbon $to): array
    {
        $row = DB::table('host_earnings')
            ->join('hosts', 'hosts.id', '=', 'host_earnings.host_id')
            ->where('hosts.agency_id', $agency->id)
            ->whereBetween('host_earnings.date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(host_earnings.diamonds_earned), 0) AS diamonds')
            ->selectRaw('COALESCE(SUM(host_earnings.gross_paise), 0) AS gross')
            ->selectRaw('COALESCE(SUM(host_earnings.agency_cut_paise), 0) AS agency_cut')
            ->selectRaw('COALESCE(SUM(host_earnings.net_paise), 0) AS host_cut')
            ->selectRaw('COALESCE(SUM(host_earnings.room_hours), 0) AS hours')
            ->selectRaw('COUNT(DISTINCT host_earnings.host_id) AS earning_hosts')
            ->first();

        return [
            'diamonds'         => (int) $row->diamonds,
            'gross_paise'      => (int) $row->gross,
            'agency_cut_paise' => (int) $row->agency_cut,
            'host_cut_paise'   => (int) $row->host_cut,
            'room_hours'       => (int) $row->hours,
            'earning_hosts'    => (int) $row->earning_hosts,
            'total_hosts'      => $agency->hosts()->active()->count(),
        ];
    }
}
