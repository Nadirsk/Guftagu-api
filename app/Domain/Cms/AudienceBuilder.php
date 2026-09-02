<?php

namespace App\Domain\Cms;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * GFT-105 — who a campaign would reach (A.10a).
 *
 * A.10a asks that "the audience count is shown before sending". That number is only
 * honest if the preview and the send resolve the **same** query, so both go through here
 * rather than the controller assembling its own `where` clauses.
 *
 * It also reports `reachable` separately from `matched`: a user with no active device and
 * no FCM token matches the segment but cannot be pushed to. Reporting only the segment
 * size would promise a reach the platform does not have.
 */
class AudienceBuilder
{
    /** Every filter the composer offers. Anything else is rejected, not ignored. */
    public const FILTERS = [
        'status',            // active suspended banned
        'country',
        'language',
        'kyc_status',
        'registered_after',
        'registered_before',
        'active_within_days',
        'recharged_within_days',
        'min_coins_spent',
        'is_host',
        'vip_only',
    ];

    /**
     * @param  array<string, mixed>  $filter
     *
     * @throws CmsException
     */
    public function query(string $audience, array $filter = [], array $userIds = []): Builder
    {
        $query = User::query()->where('status', '!=', User::STATUS_DELETED);

        if ($audience === 'user_list') {
            if ($userIds === []) {
                throw new CmsException('VALIDATION_ERROR', 'A user-list campaign needs at least one user.', 422);
            }

            return $query->whereIn('id', $userIds);
        }

        if ($audience === 'all') {
            return $query;
        }

        $unknown = array_diff(array_keys($filter), self::FILTERS);

        if ($unknown !== []) {
            // Silently dropping an unrecognised filter would show a preview count for a
            // wider audience than the operator asked for, and then send to it.
            throw new CmsException(
                'UNKNOWN_FILTER',
                'Unrecognised audience filter: '.implode(', ', $unknown).'.',
                422,
            );
        }

        return $this->apply($query, $filter);
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    protected function apply(Builder $query, array $filter): Builder
    {
        if (filled($filter['status'] ?? null)) {
            $query->where('status', $filter['status']);
        }

        if (filled($filter['country'] ?? null)) {
            $query->whereHas('profile', fn (Builder $p) => $p->where('country', $filter['country']));
        }

        if (filled($filter['language'] ?? null)) {
            $query->whereHas('profile', fn (Builder $p) => $p->where('language', $filter['language']));
        }

        if (filled($filter['kyc_status'] ?? null)) {
            $query->whereHas('kyc', fn (Builder $k) => $k->where('status', $filter['kyc_status']));
        }

        if (filled($filter['registered_after'] ?? null)) {
            $query->where('created_at', '>=', Carbon::parse($filter['registered_after'])->startOfDay());
        }

        if (filled($filter['registered_before'] ?? null)) {
            $query->where('created_at', '<=', Carbon::parse($filter['registered_before'])->endOfDay());
        }

        if (filled($filter['active_within_days'] ?? null)) {
            $query->where('last_active_at', '>=', now()->subDays((int) $filter['active_within_days']));
        }

        // "Recharged in the last 30 days" — A.10a's worked example. Read off the coin
        // ledger, because that is where a recharge actually lands.
        if (filled($filter['recharged_within_days'] ?? null)) {
            $since = now()->subDays((int) $filter['recharged_within_days']);

            $query->whereHas('coinTransactions', fn ($t) => $t
                ->where('direction', 'credit')
                ->whereIn('type', ['recharge', 'purchase'])
                ->where('created_at', '>=', $since));
        }

        if (filled($filter['min_coins_spent'] ?? null)) {
            $query->whereHas('wallet', fn ($w) => $w->where('lifetime_coins_spent', '>=', (int) $filter['min_coins_spent']));
        }

        if (($filter['is_host'] ?? null) === true) {
            $query->whereHas('hostProfile', fn ($h) => $h->where('status', 'approved'));
        }

        if (($filter['vip_only'] ?? null) === true) {
            // No VIP membership table until D.7, so this cannot be narrowed honestly.
            // Refusing beats returning the whole platform under a "VIP only" label.
            throw new CmsException(
                'FILTER_UNAVAILABLE',
                'VIP membership records land with D.7, so a VIP-only audience cannot be resolved yet.',
                422,
            );
        }

        return $query;
    }

    /**
     * Size the audience, and how much of it can actually be reached.
     *
     * @param  array<string, mixed>  $filter
     * @param  array<int, int>  $userIds
     * @return array{matched: int, reachable_push: int, unreachable: int, note: string|null}
     *
     * @throws CmsException
     */
    public function preview(string $audience, array $filter = [], array $userIds = [], array $channels = ['push']): array
    {
        $query = $this->query($audience, $filter, $userIds);

        $matched = (clone $query)->count();

        $reachable = (clone $query)
            ->whereHas('devices', fn ($d) => $d->where('is_active', true)->whereNotNull('fcm_token'))
            ->count();

        $pushOnly = $channels === ['push'];

        return [
            'matched'        => $matched,
            'reachable_push' => $reachable,
            'unreachable'    => $matched - $reachable,
            // Being explicit here is the difference between "we told 40,000 people" and
            // "we told the 12,000 of them who have the app installed with notifications on".
            'note' => $pushOnly && $matched > $reachable
                ? sprintf(
                    '%s of %s have no active device with a push token, so a push-only campaign would not reach them. Add the in-app channel to hold a message for them.',
                    number_format($matched - $reachable),
                    number_format($matched),
                )
                : null,
        ];
    }

    /** A sample of who is in the audience, so the operator can sanity-check the filter. */
    public function sample(string $audience, array $filter = [], array $userIds = [], int $limit = 10): array
    {
        return $this->query($audience, $filter, $userIds)
            ->with('profile:id,user_id,display_name')
            ->limit($limit)
            ->get()
            ->map(fn (User $u) => [
                'id'           => $u->id,
                'guftagu_id'   => $u->guftagu_id,
                'display_name' => $u->profile?->display_name,
            ])
            ->all();
    }

    /** Active devices behind an audience — what a real fan-out would iterate. */
    public function deviceCount(string $audience, array $filter = [], array $userIds = []): int
    {
        $userQuery = $this->query($audience, $filter, $userIds);

        return Device::query()
            ->where('is_active', true)
            ->whereNotNull('fcm_token')
            ->whereIn('user_id', $userQuery->select('users.id'))
            ->count();
    }
}
