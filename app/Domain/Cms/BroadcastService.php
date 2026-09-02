<?php

namespace App\Domain\Cms;

use App\Domain\Audit\AuditLogger;
use App\Models\AdminUser;
use App\Models\Broadcast;
use App\Models\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GFT-105 — broadcast campaigns (A.10a).
 *
 * **What this does and does not do.** The audience builder, the preview count, scheduling,
 * approval and the stats columns are all real. The push fan-out itself is not: FCM
 * delivery needs credentials and a mobile app that registers tokens, both of which arrive
 * with D.1/E.2c. Rather than pretending, `send()` writes the in-app notification rows it
 * genuinely can, records `sent_count` as the number it actually created, and reports
 * `push_dispatched: false` with a note. `delivered_count` and `opened_count` stay at zero
 * because nothing has reported back — inventing a delivery rate would be worse than
 * admitting there is not one yet.
 *
 * The audience size is **frozen onto the row when it is sent**. Re-counting it later would
 * silently rewrite history as users sign up or churn.
 */
class BroadcastService
{
    public function __construct(
        protected AudienceBuilder $audience,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CmsException
     */
    public function create(array $data, AdminUser $actor): Broadcast
    {
        // Resolving the audience now means an impossible filter is refused at creation
        // rather than at 3am when the schedule fires.
        $this->audience->query(
            $data['audience'] ?? 'all',
            $data['audience_filter'] ?? [],
            $data['user_ids'] ?? [],
        );

        $broadcast = Broadcast::create([
            'title'           => $data['title'],
            'body'            => $data['body'],
            'image_url'       => $data['image_url'] ?? null,
            'deep_link'       => $data['deep_link'] ?? null,
            'audience'        => $data['audience'] ?? 'all',
            'audience_filter' => $this->packFilter($data),
            'channels'        => $data['channels'] ?? ['in_app'],
            'scheduled_at'    => isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null,
            'status'          => isset($data['scheduled_at']) ? Broadcast::SCHEDULED : Broadcast::DRAFT,
            'created_by'      => $actor->id,
        ]);

        $this->audit->log($actor, 'broadcast.create', 'cms', Broadcast::class, $broadcast->id, null, [
            'title' => $broadcast->title, 'audience' => $broadcast->audience,
        ]);

        return $broadcast;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CmsException
     */
    public function update(Broadcast $broadcast, array $data, AdminUser $actor): Broadcast
    {
        if (! $broadcast->isEditable()) {
            throw new CmsException('BAD_REQUEST', "That campaign is already {$broadcast->status}.", 400);
        }

        $before = $broadcast->only(['title', 'body', 'audience', 'scheduled_at', 'status']);

        $broadcast->fill([
            'title'        => $data['title'] ?? $broadcast->title,
            'body'         => $data['body'] ?? $broadcast->body,
            'image_url'    => $data['image_url'] ?? $broadcast->image_url,
            'deep_link'    => $data['deep_link'] ?? $broadcast->deep_link,
            'audience'     => $data['audience'] ?? $broadcast->audience,
            'channels'     => $data['channels'] ?? $broadcast->channels,
            'scheduled_at' => array_key_exists('scheduled_at', $data)
                ? ($data['scheduled_at'] === null ? null : Carbon::parse($data['scheduled_at']))
                : $broadcast->scheduled_at,
        ]);

        if (array_key_exists('audience_filter', $data) || array_key_exists('user_ids', $data)) {
            $broadcast->audience_filter = $this->packFilter($data);
        }

        // Validate the combination that will actually be stored.
        $this->audience->query(
            $broadcast->audience,
            $broadcast->audience_filter['filter'] ?? [],
            $broadcast->audience_filter['user_ids'] ?? [],
        );

        $broadcast->status = $broadcast->scheduled_at === null ? Broadcast::DRAFT : Broadcast::SCHEDULED;
        $broadcast->save();

        $this->audit->log($actor, 'broadcast.update', 'cms', Broadcast::class, $broadcast->id, $before, $data);

        return $broadcast->refresh();
    }

    /**
     * The preview A.10a requires before sending.
     *
     * @return array<string, mixed>
     *
     * @throws CmsException
     */
    public function preview(Broadcast $broadcast): array
    {
        $filter = $broadcast->audience_filter['filter'] ?? [];
        $userIds = $broadcast->audience_filter['user_ids'] ?? [];

        return $this->audience->preview($broadcast->audience, $filter, $userIds, $broadcast->channels ?? ['push'])
            + ['sample' => $this->audience->sample($broadcast->audience, $filter, $userIds)];
    }

    /**
     * Send it.
     *
     * In-app notification rows are written in chunks so a large audience does not build one
     * enormous insert; the push leg is reported as not dispatched until FCM exists.
     *
     * @return array<string, mixed>
     *
     * @throws CmsException
     */
    public function send(Broadcast $broadcast, AdminUser $actor): array
    {
        if ($broadcast->isSent()) {
            throw new CmsException('ALREADY_SENT', 'That campaign has already been sent.', 409);
        }

        if (! in_array($broadcast->status, [Broadcast::DRAFT, Broadcast::SCHEDULED], true)) {
            throw new CmsException('BAD_REQUEST', "That campaign is {$broadcast->status} and cannot be sent.", 400);
        }

        $filter = $broadcast->audience_filter['filter'] ?? [];
        $userIds = $broadcast->audience_filter['user_ids'] ?? [];

        $query = $this->audience->query($broadcast->audience, $filter, $userIds);
        $matched = (clone $query)->count();

        if ($matched === 0) {
            throw new CmsException('EMPTY_AUDIENCE', 'That audience matches nobody. Nothing would be sent.', 422);
        }

        $channels = $broadcast->channels ?: ['in_app'];
        $wantsInApp = in_array('in_app', $channels, true);
        $created = 0;

        $broadcast->forceFill(['status' => Broadcast::SENDING])->save();

        if ($wantsInApp) {
            $now = now();

            (clone $query)->select('id')->chunkById(500, function ($users) use ($broadcast, $now, &$created) {
                $rows = $users->map(fn ($user) => [
                    'user_id'    => $user->id,
                    'type'       => 'broadcast',
                    'title'      => $broadcast->title,
                    'body'       => $broadcast->body,
                    'data'       => json_encode(['broadcast_uuid' => $broadcast->uuid]),
                    'image_url'  => $broadcast->image_url,
                    'deep_link'  => $broadcast->deep_link,
                    'channel'    => 'in_app',
                    'is_read'    => false,
                    'sent_at'    => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('notifications')->insert($rows);
                $created += count($rows);
            });
        }

        $reachablePush = $this->audience->deviceCount($broadcast->audience, $filter, $userIds);

        $broadcast->forceFill([
            'status'         => Broadcast::SENT,
            // Frozen: what the audience was at the moment it went out.
            'audience_count' => $matched,
            // Only what was genuinely created. Not the audience size.
            'sent_count'     => $created,
            'sent_at'        => now(),
            'approved_by'    => $actor->id,
        ])->save();

        $this->audit->log($actor, 'broadcast.send', 'cms', Broadcast::class, $broadcast->id, null, [
            'audience_count' => $matched, 'in_app_created' => $created,
        ]);

        return [
            'audience_count'   => $matched,
            'in_app_created'   => $created,
            'push_reachable'   => $reachablePush,
            // The honest part: no FCM credentials, no mobile app registering tokens.
            'push_dispatched'  => false,
            'note' => in_array('push', $channels, true)
                ? sprintf(
                    'Push was requested and %s devices would be reachable, but FCM fan-out lands with E.2c — nothing was pushed. The in-app copy is waiting for them.',
                    number_format($reachablePush),
                )
                : null,
            'stats_note' => 'Delivered and opened stay at zero until the app reports receipts back.',
        ];
    }

    /**
     * @throws CmsException
     */
    public function cancel(Broadcast $broadcast, AdminUser $actor): Broadcast
    {
        if ($broadcast->isSent()) {
            throw new CmsException('BAD_REQUEST', 'That campaign has been sent. It cannot be recalled.', 400);
        }

        $broadcast->forceFill(['status' => Broadcast::CANCELLED])->save();

        $this->audit->log($actor, 'broadcast.cancel', 'cms', Broadcast::class, $broadcast->id, null, null);

        return $broadcast->refresh();
    }

    /**
     * Campaigns whose scheduled moment has passed and which have not gone out.
     *
     * Derived from the clock, so the list is right even if the sender has not run.
     *
     * @return \Illuminate\Support\Collection<int, Broadcast>
     */
    public function due(): \Illuminate\Support\Collection
    {
        return Broadcast::query()
            ->where('status', Broadcast::SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function packFilter(array $data): ?array
    {
        $filter = $data['audience_filter'] ?? [];
        $userIds = $data['user_ids'] ?? [];

        if ($filter === [] && $userIds === []) {
            return null;
        }

        return array_filter([
            'filter'   => $filter ?: null,
            'user_ids' => $userIds ?: null,
        ]) ?: null;
    }

    /**
     * B.5b — "reach, open rate and attributable recharge revenue are reported".
     *
     * Two of the three are real. The third is honest about what it is:
     *
     *  - **reach** is `sent_count`, what was actually written.
     *  - **open rate** needs receipts from the app, which do not exist yet (E.2c), so it
     *    comes back null rather than as 0%.
     *  - **attributable recharge** is measured as recharges by recipients in the window
     *    after the send. That is *correlation*, not attribution — nobody clicked a tracked
     *    link — so it is labelled `attribution: correlated` and the note says so. A number
     *    presented as causal here would end up in a client report as if it were.
     *
     * @return array<string, mixed>
     */
    public function outcome(Broadcast $broadcast, int $windowHours = 72): array
    {
        if (! $broadcast->isSent() || $broadcast->sent_at === null) {
            return [
                'sent'  => false,
                'note'  => 'Not sent yet, so there is nothing to measure.',
            ];
        }

        $recipients = DB::table('notifications')
            ->where('type', 'broadcast')
            ->whereJsonContains('data->broadcast_uuid', $broadcast->uuid)
            ->pluck('user_id')
            ->filter()
            ->all();

        $until = $broadcast->sent_at->copy()->addHours($windowHours);

        $recharges = $recipients === [] ? null : DB::table('coin_transactions')
            ->whereIn('user_id', $recipients)
            ->where('type', 'recharge')
            ->where('direction', 'credit')
            ->whereBetween('created_at', [$broadcast->sent_at, $until])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount), 0) AS coins, COUNT(DISTINCT user_id) AS people')
            ->first();

        return [
            'sent'            => true,
            'sent_at'         => $broadcast->sent_at->toIso8601ZuluString(),
            'audience_count'  => $broadcast->audience_count,
            'reach'           => $broadcast->sent_count,
            'delivered'       => $broadcast->delivered_count,
            'opened'          => $broadcast->opened_count,
            // Null, not 0% — nothing has reported back, which is not the same as nobody
            // having opened it.
            'delivery_rate'   => $broadcast->deliveryRate(),
            'open_rate'       => $broadcast->openRate(),
            'window_hours'    => $windowHours,
            'recharges'       => $recharges === null ? 0 : (int) $recharges->n,
            'recharging_users' => $recharges === null ? 0 : (int) $recharges->people,
            'coins_purchased' => $recharges === null ? 0 : (int) $recharges->coins,
            'attribution'     => 'correlated',
            'note' => sprintf(
                'Recharges are those made by recipients within %d hours of the send. Nobody clicked a tracked link, so this is correlation, not attribution — some of it would have happened anyway.',
                $windowHours,
            ),
        ];
    }
}
