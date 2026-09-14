<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Events\EventCampaignService;
use App\Domain\Events\EventException;
use App\Domain\Events\EventService;
use App\Domain\Events\LuckyDrawService;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventClaim;
use App\Models\EventProgress;
use App\Models\EventReward;
use App\Models\EventTier;
use App\Models\EventTierReward;
use App\Models\LuckyDraw;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Epic A.9a/b — events, tournaments and lucky draws — plus the template-driven campaign
 * events (`recharge_activity`, `weekly_star`) built on `event_tiers`. docs/03 §12.
 */
class EventController extends Controller
{
    public function __construct(
        protected EventService $events,
        protected LuckyDrawService $draws,
        protected EventCampaignService $campaigns,
        protected AuditLogger $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'     => ['sometimes', 'nullable', Rule::in(Event::TYPES)],
            'phase'    => ['sometimes', 'nullable', Rule::in(['draft', 'upcoming', 'live', 'ended', 'cancelled'])],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Event::query()
            ->withCount('participants')
            ->with('createdBy:id,name')
            ->when($data['type'] ?? null, fn ($q, string $t) => $q->where('type', $t))
            ->when($data['phase'] ?? null, function ($q, string $phase) {
                // Phase is derived from the clock, so filtering happens in SQL against the
                // same window the reader would compute.
                return match ($phase) {
                    'draft'     => $q->where('status', Event::DRAFT),
                    'cancelled' => $q->where('status', Event::CANCELLED),
                    'upcoming'  => $q->upcoming(),
                    'live'      => $q->liveNow(),
                    'ended'     => $q->ended(),
                };
            })
            ->orderByDesc('starts_at');

        $paginator = $query->paginate(
            perPage: (int) ($data['per_page'] ?? 25),
            page: (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (Event $event) => $this->payload($event)
        )->all());
    }

    public function show(Event $event): JsonResponse
    {
        $event->load(['rewards', 'luckyDraw', 'createdBy:id,name']);

        $participants = $event->participants()
            ->with('user.profile:id,user_id,display_name')
            ->orderByRaw('`rank` IS NULL, `rank`')
            ->orderByDesc('score')
            ->limit(100)
            ->get();

        return ApiResponse::success([
            'event'   => $this->payload($event),
            'rewards' => $event->rewards->map(fn (EventReward $r) => [
                'id'            => $r->id,
                'rank_from'     => $r->rank_from,
                'rank_to'       => $r->rank_to,
                'reward_type'   => $r->reward_type,
                'reward_value'  => $r->reward_value,
                'quantity'      => $r->quantity,
                'claimed_count' => $r->claimed_count,
                // Cosmetic rewards have no inventory table until D.7, so say so rather
                // than reporting a grant that did not happen.
                'payable'       => in_array($r->reward_type, ['coins', 'diamonds'], true),
            ]),
            'participants' => $participants->map(fn ($p) => [
                'user_id'      => $p->user_id,
                'guftagu_id'   => $p->user?->guftagu_id,
                'display_name' => $p->user?->profile?->display_name,
                'score'        => $p->score,
                'rank'         => $p->rank,
                'status'       => $p->status,
            ]),
            'lucky_draw' => $event->luckyDraw === null ? null : $this->drawPayload($event->luckyDraw),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateEvent($request);

        $event = DB::transaction(function () use ($data, $request) {
            $event = Event::create([...$data, 'created_by' => $request->user()->id]);

            if ($event->type === 'lucky_draw') {
                // The commitment is made now, before anyone can enter.
                $this->draws->create($event, [
                    'draw_at'      => $data['draw_at'] ?? $event->ends_at,
                    'winner_count' => $data['winner_count'] ?? 1,
                    'algorithm'    => $data['algorithm'] ?? 'random',
                ]);
            }

            return $event;
        });

        $this->audit->log($request->user(), 'event.create', 'events', Event::class, $event->id, null, $data);

        return ApiResponse::success($this->payload($event->fresh(['luckyDraw'])), 'Event created', 201);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $data = $this->validateEvent($request, false);

        // Changing the window of a finished event would rewrite what already happened.
        if ($event->hasEnded() && (isset($data['starts_at']) || isset($data['ends_at']))) {
            throw new EventException(
                'BAD_REQUEST',
                'This event has already ended — its dates cannot be moved.',
                400,
            );
        }

        $before = $event->only(array_keys($data));
        $event->fill($data)->save();

        $this->audit->log($request->user(), 'event.update', 'events', Event::class, $event->id, $before, $data);

        return ApiResponse::success($this->payload($event->fresh()), 'Event updated');
    }

    /** Publish a draft — after this the clock, not an operator, decides the phase. */
    public function publish(Request $request, Event $event): JsonResponse
    {
        if ($event->status !== Event::DRAFT) {
            return ApiResponse::error('BAD_REQUEST', 'Only a draft can be published.', ['status' => $event->status], 400);
        }

        if ($event->ends_at->isPast()) {
            return ApiResponse::error('BAD_REQUEST', 'That event would already be over.', null, 400);
        }

        $event->forceFill(['status' => Event::SCHEDULED, 'approved_by' => $request->user()->id])->save();

        $this->audit->log($request->user(), 'event.publish', 'events', Event::class, $event->id, ['status' => Event::DRAFT], ['status' => Event::SCHEDULED]);

        return ApiResponse::success(['phase' => $event->phase()], 'Event published');
    }

    public function cancel(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $before = $event->status;
        $event->forceFill(['status' => Event::CANCELLED])->save();

        $this->audit->log($request->user(), 'event.cancel', 'events', Event::class, $event->id, ['status' => $before], ['status' => Event::CANCELLED, 'reason' => $data['reason']]);

        return ApiResponse::success(['phase' => $event->phase()], 'Event cancelled');
    }

    /** GFT-093 — reward bands. */
    public function addReward(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'rank_from'    => ['required', 'integer', 'min:1'],
            'rank_to'      => ['required', 'integer', 'gte:rank_from'],
            'reward_type'  => ['required', Rule::in(EventReward::TYPES)],
            'reward_value' => ['required', 'integer', 'min:1'],
            'quantity'     => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        // Overlapping bands would make "the reward for their band" ambiguous.
        $clash = $event->rewards()
            ->where('rank_from', '<=', $data['rank_to'])
            ->where('rank_to', '>=', $data['rank_from'])
            ->first();

        if ($clash !== null) {
            throw new EventException(
                'VALIDATION_ERROR',
                'That rank range overlaps an existing reward band.',
                422,
                ['overlapping' => ['rank_from' => $clash->rank_from, 'rank_to' => $clash->rank_to]],
            );
        }

        $reward = $event->rewards()->create($data);

        return ApiResponse::success(['id' => $reward->id], 'Reward band added', 201);
    }

    public function removeReward(Request $request, Event $event, EventReward $reward): JsonResponse
    {
        if ($reward->claimed_count > 0) {
            return ApiResponse::error(
                'BAD_REQUEST',
                'This band has already been paid out and cannot be removed.',
                ['claimed_count' => $reward->claimed_count],
                400,
            );
        }

        $reward->delete();

        return ApiResponse::success(null, 'Reward band removed');
    }

    /** GFT-093 — hand out the rewards once the event is over. */
    public function distribute(Request $request, Event $event): JsonResponse
    {
        $result = $this->events->distributeRewards($event, $request->user());

        return ApiResponse::success($result, sprintf(
            '%d of %d eligible participants rewarded%s',
            $result['granted'],
            $result['eligible'],
            $result['skipped'] > 0 ? " ({$result['skipped']} already had theirs)" : '',
        ));
    }

    // ------------------------------------------------------------ lucky draws

    /** GFT-095 — run the draw and publish the seed. */
    public function runDraw(Request $request, Event $event): JsonResponse
    {
        $draw = $event->luckyDraw;

        if ($draw === null) {
            return ApiResponse::error('NOT_FOUND', 'This event is not a lucky draw.', null, 404);
        }

        $result = $this->draws->draw($draw, $request->user());

        return ApiResponse::success($this->drawPayload($result), 'Draw complete — the seed is now public');
    }

    /** Recompute a published result from its seed, the way an outsider would check it. */
    public function verifyDraw(Event $event): JsonResponse
    {
        $draw = $event->luckyDraw;

        if ($draw === null || ! $draw->hasRun()) {
            return ApiResponse::error('BAD_REQUEST', 'That draw has not been run yet.', null, 400);
        }

        return ApiResponse::success(
            LuckyDrawService::verify($draw),
            'Recomputed from the published seed',
        );
    }

    // ----------------------------------------------------------------- shared

    /** @return array<string, mixed> */
    protected function validateEvent(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'type'             => [$creating ? 'required' : 'sometimes', Rule::in(Event::TYPES)],
            'title_en'         => [$required, 'string', 'max:150'],
            'title_hi'         => ['sometimes', 'nullable', 'string', 'max:150'],
            'description'      => ['sometimes', 'nullable', 'string', 'max:5000'],
            'banner_url'       => ['sometimes', 'nullable', 'string', 'max:500'],
            'entry_type'       => ['sometimes', Rule::in(['free', 'coins', 'invite'])],
            'entry_cost'       => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'starts_at'        => [$required, 'date'],
            'ends_at'          => [$required, 'date', 'after:starts_at'],
            'max_participants' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_featured'      => ['sometimes', 'boolean'],
            // Lucky-draw specifics, only meaningful at creation.
            'draw_at'          => ['sometimes', 'nullable', 'date'],
            'winner_count'     => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'algorithm'        => ['sometimes', Rule::in(['random', 'weighted'])],
            // Campaign events (recharge_activity, weekly_star, custom) only.
            'period'           => ['sometimes', 'nullable', Rule::in(Event::PERIODS)],
            'progress_metric'  => ['sometimes', 'nullable', Rule::in(Event::PROGRESS_METRICS)],
            // Only meaningful for gift_value/gift_count — which table it addresses.
            'metric_ref_type'  => ['sometimes', 'nullable', Rule::in(Event::METRIC_REF_TYPES)],
            'metric_ref_id'    => ['sometimes', 'nullable', 'integer', 'min:1'],
            'ranking_rule_key' => ['sometimes', 'nullable', 'string', Rule::exists('ranking_rules', 'key')],
            // An ordered array of block instances: {id, type, config, style}. `type` is
            // the only part validated here — `config`/`style` shape varies per block type
            // and the app simply ignores a field it does not recognise, so there is
            // nothing to gain from validating them deeply and a real cost (a new block
            // type or style field would need this touched every time).
            'layout'           => ['sometimes', 'nullable', 'array'],
            'layout.*.id'      => ['required_with:layout', 'string'],
            'layout.*.type'    => ['required_with:layout', Rule::in(Event::BLOCK_TYPES)],
            'layout.*.config'  => ['sometimes', 'nullable', 'array'],
            'layout.*.style'   => ['sometimes', 'nullable', 'array'],
        ]);
    }

    protected function payload(Event $event): array
    {
        return [
            'id'               => $event->id,
            'uuid'             => $event->uuid,
            'type'             => $event->type,
            'title_en'         => $event->title_en,
            'title_hi'         => $event->title_hi,
            'description'      => $event->description,
            'banner_url'       => $event->banner_url,
            'entry_type'       => $event->entry_type,
            'entry_cost'       => $event->entry_cost,
            'starts_at'        => $event->starts_at?->toIso8601ZuluString(),
            'ends_at'          => $event->ends_at?->toIso8601ZuluString(),
            // `status` is the operator's intent; `phase` is what is actually true now.
            'status'           => $event->status,
            'phase'            => $event->phase(),
            'max_participants' => $event->max_participants,
            'is_featured'      => $event->is_featured,
            'participant_count' => $event->participants_count ?? $event->participants()->count(),
            'created_by'       => $event->createdBy?->name,
            'is_campaign'      => $event->isCampaign(),
            'period'           => $event->period,
            'progress_metric'  => $event->progress_metric,
            'metric_ref_id'    => $event->metric_ref_id,
            'metric_ref_type'  => $event->metric_ref_type,
            'ranking_rule_key' => $event->ranking_rule_key,
            'layout'           => $event->layout ?? [],
        ];
    }

    // ------------------------------------------------------------- campaign tiers
    // recharge_activity (tier_type=threshold) and weekly_star (tier_type=rank_range).

    public function tiers(Event $event): JsonResponse
    {
        return ApiResponse::success(
            $event->tiers()->with('rewards')->get()->map(fn (EventTier $t) => $this->campaigns->tierPayload($t)),
        );
    }

    public function addTier(Request $request, Event $event): JsonResponse
    {
        $data = $this->validateTier($request, $event);

        $this->assertNoTierOverlap($event, $data);

        $tier = $event->tiers()->create($data);

        $this->audit->log($request->user(), 'event.tier_add', 'events', Event::class, $event->id, null, $data);

        return ApiResponse::success($this->campaigns->tierPayload($tier->fresh()), 'Tier added', 201);
    }

    public function updateTier(Request $request, Event $event, EventTier $tier): JsonResponse
    {
        if ($tier->event_id !== $event->id) {
            return ApiResponse::error('NOT_FOUND', 'That tier does not belong to this event.', null, 404);
        }

        $data = $this->validateTier($request, $event, false);
        $this->assertNoTierOverlap($event, [...$tier->only(['tier_type', 'threshold_value', 'rank_from', 'rank_to']), ...$data], $tier);

        $before = $tier->only(array_keys($data));
        $tier->fill($data)->save();

        $this->audit->log($request->user(), 'event.tier_update', 'events', Event::class, $event->id, $before, $data);

        return ApiResponse::success($this->campaigns->tierPayload($tier->fresh('rewards')), 'Tier updated');
    }

    public function removeTier(Request $request, Event $event, EventTier $tier): JsonResponse
    {
        if ($tier->event_id !== $event->id) {
            return ApiResponse::error('NOT_FOUND', 'That tier does not belong to this event.', null, 404);
        }

        if ($event->tierClaims()->where('event_tier_id', $tier->id)->exists()) {
            return ApiResponse::error('BAD_REQUEST', 'This tier has already been claimed by someone and cannot be removed.', null, 400);
        }

        $tier->delete();

        $this->audit->log($request->user(), 'event.tier_remove', 'events', Event::class, $event->id, null, ['tier_id' => $tier->id]);

        return ApiResponse::success(null, 'Tier removed');
    }

    public function addTierReward(Request $request, Event $event, EventTier $tier): JsonResponse
    {
        if ($tier->event_id !== $event->id) {
            return ApiResponse::error('NOT_FOUND', 'That tier does not belong to this event.', null, 404);
        }

        $data = $this->validateTierReward($request);
        $reward = $tier->rewards()->create($data);

        $this->audit->log($request->user(), 'event.tier_reward_add', 'events', Event::class, $event->id, null, [...$data, 'tier_id' => $tier->id]);

        return ApiResponse::success($this->campaigns->rewardPayload($reward), 'Reward line added', 201);
    }

    public function removeTierReward(Event $event, EventTier $tier, EventTierReward $reward): JsonResponse
    {
        if ($tier->event_id !== $event->id || $reward->event_tier_id !== $tier->id) {
            return ApiResponse::error('NOT_FOUND', 'That reward line does not belong to this tier.', null, 404);
        }

        $reward->delete();

        return ApiResponse::success(null, 'Reward line removed');
    }

    /** Admin preview of standings/progress — the same data the app screen renders from. */
    public function progress(Request $request, Event $event): JsonResponse
    {
        $hasRankTiers = $event->ranking_rule_key !== null
            || $event->tiers()->where('tier_type', EventTier::RANK_RANGE)->exists();

        if ($hasRankTiers) {
            return ApiResponse::success(['board' => $this->campaigns->board($event)]);
        }

        $periodType = $request->query('period', $event->period ?? EventCampaignService::FIXED);

        $rows = EventProgress::query()
            ->where('event_id', $event->id)
            ->where('period_type', $periodType)
            ->orderByDesc('score')
            ->with('user.profile:id,user_id,display_name')
            ->limit(200)
            ->get();

        return ApiResponse::success(['progress' => $rows->map(fn ($row) => [
            'user_id'      => $row->user_id,
            'display_name' => $row->user?->profile?->display_name,
            'score'        => $row->score,
            'period_start' => $row->period_start->toDateString(),
            'period_end'   => $row->period_end->toDateString(),
        ])]);
    }

    /** GFT-093-style — hand out rank-band bundles once a weekly_star period has closed. */
    public function distributeTiers(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate(['period_start' => ['sometimes', 'nullable', 'date']]);

        $result = $this->campaigns->distributeTiers(
            $event,
            $request->user(),
            isset($data['period_start']) ? Carbon::parse($data['period_start']) : null,
        );

        $this->audit->log($request->user(), 'event.tiers_distribute', 'events', Event::class, $event->id, null, $result);

        return ApiResponse::success($result, sprintf(
            '%d of %d eligible ranks paid%s',
            $result['granted'],
            $result['eligible'],
            $result['skipped'] > 0 ? " ({$result['skipped']} already had theirs)" : '',
        ));
    }

    /**
     * A `manual` catalog reward records a claim but grants nothing automatically — this
     * is where support finds what still needs handing out by hand, per event.
     */
    public function manualFulfillments(Event $event): JsonResponse
    {
        $claims = $event->tierClaims()
            ->where('status', EventClaim::PAID)
            ->with(['tier', 'user.profile:id,user_id,display_name'])
            ->orderByDesc('claimed_at')
            ->get();

        $pending = [];

        foreach ($claims as $claim) {
            foreach ($claim->granted ?? [] as $index => $line) {
                if (($line['needs_manual_fulfillment'] ?? false) && empty($line['fulfilled_at'])) {
                    $pending[] = [
                        'claim_id'     => $claim->id,
                        'line_index'   => $index,
                        'user_id'      => $claim->user_id,
                        'display_name' => $claim->user?->profile?->display_name,
                        'tier_label'   => $claim->tier?->label,
                        'name'         => $line['name'] ?? null,
                        'reward_value' => $line['reward_value'] ?? null,
                        'duration_days' => $line['duration_days'] ?? null,
                        'claimed_at'   => $claim->claimed_at?->toIso8601ZuluString(),
                    ];
                }
            }
        }

        return ApiResponse::success($pending);
    }

    /** Marks one reward line of one claim as handled by support — outside this system. */
    public function fulfillManual(Request $request, Event $event, EventClaim $claim): JsonResponse
    {
        if ($claim->event_id !== $event->id) {
            return ApiResponse::error('NOT_FOUND', 'That claim does not belong to this event.', null, 404);
        }

        $data = $request->validate(['line_index' => ['required', 'integer', 'min:0']]);
        $granted = $claim->granted ?? [];

        if (! isset($granted[$data['line_index']])) {
            return ApiResponse::error('NOT_FOUND', 'No such reward line on that claim.', null, 404);
        }

        $granted[$data['line_index']]['fulfilled_at'] = now()->toIso8601ZuluString();
        $granted[$data['line_index']]['fulfilled_by'] = $request->user()->name;
        $claim->forceFill(['granted' => $granted])->save();

        $this->audit->log($request->user(), 'event.manual_fulfilled', 'events', Event::class, $event->id, null, [
            'claim_id' => $claim->id, 'line_index' => $data['line_index'],
        ]);

        return ApiResponse::success(null, 'Marked as fulfilled');
    }

    protected function validateTier(Request $request, Event $event, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'tier_type'       => [$required, Rule::in(EventTier::TYPES)],
            'period'          => ['sometimes', 'nullable', Rule::in(Event::PERIODS)],
            'threshold_value' => ['required_if:tier_type,threshold', 'nullable', 'integer', 'min:1'],
            'rank_from'       => ['required_if:tier_type,rank_range', 'nullable', 'integer', 'min:1'],
            'rank_to'         => ['required_if:tier_type,rank_range', 'nullable', 'integer', 'gte:rank_from'],
            'label'           => [$required, 'string', 'max:60'],
            'image_url'       => ['sometimes', 'nullable', 'string', 'max:500'],
            'sort_order'      => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    protected function validateTierReward(Request $request): array
    {
        return $request->validate([
            'reward_catalog_id' => ['required', 'integer', Rule::exists('reward_catalog_items', 'id')],
            'reward_value'      => ['sometimes', 'nullable', 'integer', 'min:0'],
            'duration_days'     => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'label'             => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort_order'        => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    /** Rank bands, and thresholds, must not overlap within one event — same reasoning as EventReward/RankingReward. */
    protected function assertNoTierOverlap(Event $event, array $data, ?EventTier $ignoring = null): void
    {
        $query = $event->tiers()->when($ignoring, fn ($q) => $q->where('id', '!=', $ignoring->id));

        if (($data['tier_type'] ?? null) === EventTier::RANK_RANGE) {
            $clash = (clone $query)
                ->where('tier_type', EventTier::RANK_RANGE)
                ->where('rank_from', '<=', $data['rank_to'])
                ->where('rank_to', '>=', $data['rank_from'])
                ->first();

            if ($clash !== null) {
                throw new EventException('VALIDATION_ERROR', 'That rank range overlaps an existing tier.', 422, [
                    'overlapping' => ['rank_from' => $clash->rank_from, 'rank_to' => $clash->rank_to],
                ]);
            }
        }

        if (($data['tier_type'] ?? null) === EventTier::THRESHOLD) {
            $clash = (clone $query)
                ->where('tier_type', EventTier::THRESHOLD)
                ->where('threshold_value', $data['threshold_value'])
                ->first();

            if ($clash !== null) {
                throw new EventException('VALIDATION_ERROR', 'A tier at that threshold already exists.', 422);
            }
        }
    }

    protected function drawPayload(LuckyDraw $draw): array
    {
        return [
            'id'           => $draw->id,
            'draw_at'      => $draw->draw_at?->toIso8601ZuluString(),
            'winner_count' => $draw->winner_count,
            'algorithm'    => $draw->algorithm,
            // Published from the start — this is the commitment.
            'seed_hash'    => $draw->seed_hash,
            // Only after the draw. Before then the model returns null, so the winners
            // cannot be computed in advance by anyone, including staff reading the API.
            'seed'         => $draw->revealedSeed(),
            'drawn_at'     => $draw->drawn_at?->toIso8601ZuluString(),
            'result'       => $draw->result,
            'has_run'      => $draw->hasRun(),
        ];
    }
}
