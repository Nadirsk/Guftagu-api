<?php

namespace App\Http\Controllers\Api;

use App\Domain\Events\EventCampaignService;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventTier;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app-facing half of the Event Builder — `recharge_activity` and `weekly_star`
 * screens are rendered entirely from what these endpoints return (banner, countdown,
 * `layout`, tiers/bundles, and this user's own progress or rank). Nothing about a
 * specific event is hardcoded in the app; a new one is a new admin-configured row.
 */
class EventController extends Controller
{
    public function __construct(protected EventCampaignService $campaigns)
    {
    }

    /** GET /events — the campaign events currently live or upcoming. */
    public function index(Request $request): JsonResponse
    {
        $events = Event::query()
            ->whereIn('type', Event::CAMPAIGN_TYPES)
            ->liveNow()
            ->orderByDesc('is_featured')
            ->orderBy('starts_at')
            ->get();

        return ApiResponse::success($events->map(fn (Event $event) => $this->summary($event)));
    }

    /** GET /events/{uuid} — full screen: layout, tiers/bundles, my progress or the board. */
    public function show(Request $request, string $eventUuid): JsonResponse
    {
        $event = Event::query()->where('uuid', $eventUuid)->firstOrFail();

        $payload = $this->summary($event);
        $payload['tiers'] = $event->tiers()->with('rewards')->get()
            ->map(fn (EventTier $t) => $this->campaigns->tierPayload($t))->values();

        $hasRankTiers = $event->ranking_rule_key !== null
            || $event->tiers()->where('tier_type', EventTier::RANK_RANGE)->exists();

        if ($hasRankTiers) {
            $payload['board'] = $this->campaigns->board($event);
        } else {
            $user = $request->user();
            $periodTypes = $event->tiers()->pluck('period')
                ->map(fn ($p) => $p ?? $event->period ?? EventCampaignService::FIXED)->unique();

            $payload['my_progress'] = $periodTypes->mapWithKeys(function (string $periodType) use ($event, $user) {
                $progress = $this->campaigns->myProgress($event, $user, $periodType);

                return [$periodType => [
                    'score'        => $progress['score'],
                    'period_start' => $progress['period_start']->toDateString(),
                    'period_end'   => $progress['period_end']->toDateString(),
                ]];
            });

            $payload['my_claims'] = $event->tierClaims()
                ->where('user_id', $user->id)
                ->where('status', 'paid')
                ->pluck('event_tier_id');
        }

        return ApiResponse::success($payload);
    }

    /** POST /events/{uuid}/tiers/{tier}/claim — the "Receive" button. */
    public function claim(Request $request, string $eventUuid, int $tierId): JsonResponse
    {
        $event = Event::query()->where('uuid', $eventUuid)->firstOrFail();
        $tier = EventTier::query()->where('event_id', $event->id)->findOrFail($tierId);

        $result = $this->campaigns->claimTier($event, $tier, $request->user());

        if (! $result['ok']) {
            return match ($result['error']) {
                'already_claimed'   => ApiResponse::error('ALREADY_CLAIMED', 'You have already claimed this reward.', null, 409),
                'threshold_not_met' => ApiResponse::error('BAD_REQUEST', 'You have not reached this tier yet.', null, 400),
                'event_not_live'    => ApiResponse::error('BAD_REQUEST', 'This event is not live right now.', null, 400),
                default             => ApiResponse::error('BAD_REQUEST', 'That reward cannot be claimed.', null, 400),
            };
        }

        return ApiResponse::success(['granted' => $result['claim']->granted], 'Reward claimed');
    }

    protected function summary(Event $event): array
    {
        return [
            'uuid'             => $event->uuid,
            'type'             => $event->type,
            'title_en'         => $event->title_en,
            'title_hi'         => $event->title_hi,
            'banner_url'       => $event->banner_url,
            'rules'            => $event->rules,
            'layout'           => $event->layout ?? [],
            'period'           => $event->period,
            'starts_at'        => $event->starts_at->toIso8601ZuluString(),
            'ends_at'          => $event->ends_at->toIso8601ZuluString(),
            // The countdown the app renders is to the *current cycle's* close for a
            // recurring event, not the (possibly months-away) end of the whole campaign.
            'cycle_ends_at'    => $this->cycleEndsAt($event)->toIso8601ZuluString(),
        ];
    }

    protected function cycleEndsAt(Event $event)
    {
        if ($event->period === null) {
            return $event->ends_at;
        }

        [, $end] = $this->campaigns->periodFor($event, $event->period);

        return $end->min($event->ends_at);
    }
}
