<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Events\EventCampaignService;
use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\Event;
use App\Models\EventRound;
use App\Models\EventTier;
use App\Models\Gift;
use App\Models\RewardCatalogItem;
use App\Models\StoreItem;
use App\Models\VipTier;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The admin side of Weekly Star, shaped like the app screen rather than the generic Event
 * Builder: a start date and a number of weeks (every round is 7 days), up to three Event
 * Gifts (only sending those scores, by coin value), and a reward bundle for each of
 * Top 1, Top 2 and Top 3. Finished rounds pay themselves (`events:settle-weekly-star`);
 * `settle` here is the manual backup.
 *
 * Publishing and cancelling stay on the shared `events/{event}/publish|cancel` routes.
 */
class WeeklyStarController extends Controller
{
    /** What the reward picker offers — the reward catalog's automated handlers. */
    public const REWARD_TYPES = ['coins', 'diamonds', 'frame', 'entry_effect', 'chat_bubble', 'vip', 'badge'];

    public const MAX_WEEKS = 52;

    public function __construct(
        protected EventCampaignService $campaigns,
        protected AuditLogger $audit,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title_en'    => ['required', 'string', 'max:150'],
            'title_hi'    => ['sometimes', 'nullable', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'starts_on'   => ['required', 'date', 'after_or_equal:today'],
            'weeks'       => ['required', 'integer', 'min:1', 'max:'.self::MAX_WEEKS],
        ]);

        $startsAt = Carbon::parse($data['starts_on'])->startOfDay();

        $event = DB::transaction(function () use ($data, $startsAt, $request) {
            $event = Event::create([
                'type'            => Event::WEEKLY_STAR,
                'title_en'        => $data['title_en'],
                'title_hi'        => $data['title_hi'] ?? null,
                'description'     => $data['description'] ?? null,
                'starts_at'       => $startsAt,
                'ends_at'         => $startsAt->copy()->addDays(Event::ROUND_DAYS * $data['weeks']),
                'period'          => 'weekly',
                'progress_metric' => 'gift_value',
                'rules'           => [
                    'Send the Event Gifts shown on the Ranking tab — each one scores its coin value.',
                    'Every round lasts 7 days. When it ends, the Top 3 senders receive their rewards automatically.',
                ],
                'status'          => Event::DRAFT,
                'created_by'      => $request->user()->id,
            ]);

            // The app's Reward tab always shows these three cards.
            foreach ([1, 2, 3] as $rank) {
                $event->tiers()->create([
                    'tier_type'  => EventTier::RANK_RANGE,
                    'rank_from'  => $rank,
                    'rank_to'    => $rank,
                    'label'      => "Top {$rank}",
                    'sort_order' => $rank,
                ]);
            }

            return $event;
        });

        $this->audit->log($request->user(), 'weekly_star.create', 'events', Event::class, $event->id, null, $data);

        return ApiResponse::success(['id' => $event->id], 'Weekly Star created as a draft', 201);
    }

    public function show(Event $event): JsonResponse
    {
        $this->assertWeeklyStar($event);

        $settled = $event->rounds()->get()->keyBy(fn (EventRound $r) => $r->period_start->toDateTimeString());
        $board = $event->isLive() ? $this->campaigns->windowedBoard($event, 10) : collect();

        $rounds = collect($this->campaigns->roundsOf($event))->map(function (array $window, int $i) use ($settled) {
            [$start, $end] = $window;
            $round = $settled->get($start->toDateTimeString());

            return [
                'week'         => $i + 1,
                'period_start' => $start->toIso8601ZuluString(),
                'period_end'   => $end->toIso8601ZuluString(),
                'state'        => match (true) {
                    $round !== null      => 'settled',
                    $end->isPast()       => 'due',
                    $start->isFuture()   => 'upcoming',
                    default              => 'live',
                },
                'standings'    => $round?->standings ?? [],
                'granted'      => $round?->granted ?? 0,
                'settled_at'   => $round?->settled_at?->toIso8601ZuluString(),
            ];
        });

        return ApiResponse::success([
            'event'  => $this->payload($event),
            'gifts'  => $event->gifts->map(fn (Gift $g) => $this->giftPayload($g))->values(),
            'tiers'  => $event->tiers()->with('rewards.catalog')->get()
                ->map(fn (EventTier $t) => $this->campaigns->tierPayload($t))->values(),
            'rounds' => $rounds->values(),
            'board'  => $board->values(),
        ]);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $this->assertWeeklyStar($event);

        $data = $request->validate([
            'title_en'    => ['sometimes', 'string', 'max:150'],
            'title_hi'    => ['sometimes', 'nullable', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'rules'       => ['sometimes', 'nullable', 'array', 'max:10'],
            'rules.*'     => ['string', 'max:300'],
            'starts_on'   => ['sometimes', 'date'],
            'weeks'       => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_WEEKS],
        ]);

        $before = $event->only(['title_en', 'starts_at', 'ends_at']);

        if (isset($data['starts_on']) && ! $event->starts_at->isSameDay(Carbon::parse($data['starts_on']))) {
            if ($event->starts_at->isPast()) {
                throw ValidationException::withMessages(['starts_on' => ['This Weekly Star has already started — its start date cannot move.']]);
            }

            if (Carbon::parse($data['starts_on'])->startOfDay()->lt(today())) {
                throw ValidationException::withMessages(['starts_on' => ['Pick today or a later date.']]);
            }

            $weeks = $this->weeksOf($event);
            $event->starts_at = Carbon::parse($data['starts_on'])->startOfDay();
            $event->ends_at = $event->starts_at->copy()->addDays(Event::ROUND_DAYS * $weeks);
        }

        if (isset($data['weeks'])) {
            $endsAt = $event->starts_at->copy()->addDays(Event::ROUND_DAYS * $data['weeks']);

            // Shortening may not cut into a round that is already running or finished.
            [, $currentRoundEnd] = $this->campaigns->roundFor($event);
            if ($event->starts_at->isPast() && $endsAt->lt($currentRoundEnd->copy()->addSecond())) {
                throw ValidationException::withMessages(['weeks' => ['That would end the campaign before the round already under way.']]);
            }

            $event->ends_at = $endsAt;
        }

        $event->fill(collect($data)->only(['title_en', 'title_hi', 'description', 'rules'])->all());

        if ($event->status === Event::SCHEDULED && ($clash = $event->clashingWeeklyStar()) !== null) {
            return $this->clashError($clash);
        }

        $event->save();

        $this->audit->log($request->user(), 'weekly_star.update', 'events', Event::class, $event->id, $before, $data);

        return ApiResponse::success($this->payload($event->fresh()), 'Weekly Star updated');
    }

    /** PUT — the Event Gifts, in tile order. At most three: the app has three tiles. */
    public function syncGifts(Request $request, Event $event): JsonResponse
    {
        $this->assertWeeklyStar($event);

        $data = $request->validate([
            'gift_ids'   => ['present', 'array', 'max:'.Event::MAX_GIFTS],
            'gift_ids.*' => ['integer', 'distinct', Rule::exists('gifts', 'id')],
        ]);

        $event->gifts()->sync(
            collect($data['gift_ids'])->values()->mapWithKeys(fn (int $id, int $i) => [$id => ['sort_order' => $i]])->all(),
        );

        $this->audit->log($request->user(), 'weekly_star.gifts', 'events', Event::class, $event->id, null, $data);

        return ApiResponse::success(
            $event->gifts()->get()->map(fn (Gift $g) => $this->giftPayload($g))->values(),
            'Event gifts saved',
        );
    }

    /**
     * Add one reward to a Top N card, picked the way Daily rewards are (a type, the item,
     * an amount or a number of days). The reward catalog row it needs is found or made
     * here, so the admin never has to visit the catalog first.
     */
    public function addReward(Request $request, Event $event, EventTier $tier): JsonResponse
    {
        $this->assertWeeklyStar($event);

        if ($tier->event_id !== $event->id) {
            return ApiResponse::error('NOT_FOUND', 'That tier does not belong to this event.', null, 404);
        }

        $data = $request->validate([
            'reward_type'   => ['required', Rule::in(self::REWARD_TYPES)],
            'ref_id'        => ['required_unless:reward_type,coins,diamonds', 'nullable', 'integer'],
            'reward_value'  => ['required_if:reward_type,coins,diamonds', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'duration_days' => ['required_if:reward_type,vip', 'nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $catalog = $this->catalogFor($data['reward_type'], $data['ref_id'] ?? null);
        $isCurrency = in_array($data['reward_type'], ['coins', 'diamonds'], true);

        $reward = $tier->rewards()->create([
            'reward_catalog_id' => $catalog->id,
            'reward_value'      => $isCurrency ? $data['reward_value'] : null,
            // A medal is kept for good unless a duration is given; currency has none.
            'duration_days'     => $isCurrency ? null : ($data['duration_days'] ?? null),
            'sort_order'        => (int) $tier->rewards()->max('sort_order') + 1,
        ]);

        $this->audit->log($request->user(), 'weekly_star.reward_add', 'events', Event::class, $event->id, null, [...$data, 'tier_id' => $tier->id]);

        return ApiResponse::success($this->campaigns->rewardPayload($reward->load('catalog')), 'Reward added', 201);
    }

    /** The manual backup for a finished round — re-running pays only whoever was missed. */
    public function settle(Request $request, Event $event): JsonResponse
    {
        $this->assertWeeklyStar($event);

        $data = $request->validate(['period_start' => ['required', 'date']]);

        $round = $this->campaigns->settleRound($event, Carbon::parse($data['period_start']), $request->user());

        $this->audit->log($request->user(), 'weekly_star.settle', 'events', Event::class, $event->id, null, [
            'period_start' => $round->period_start->toIso8601ZuluString(), 'granted' => $round->granted,
        ]);

        return ApiResponse::success(['granted' => $round->granted], 'Round settled');
    }

    // ----------------------------------------------------------------- shared

    protected function assertWeeklyStar(Event $event): void
    {
        abort_unless($event->isGiftWeeklyStar(), 404, 'That is not a Weekly Star.');
    }

    protected function weeksOf(Event $event): int
    {
        return (int) ceil($event->starts_at->diffInDays($event->ends_at) / Event::ROUND_DAYS);
    }

    protected function catalogFor(string $type, ?int $refId): RewardCatalogItem
    {
        if (in_array($type, ['coins', 'diamonds'], true)) {
            return RewardCatalogItem::firstOrCreate(
                ['handler_key' => $type, 'handler_ref_id' => null],
                ['name' => ucfirst($type), 'is_active' => true],
            );
        }

        $ref = match ($type) {
            'vip'   => VipTier::find($refId),
            'badge' => Badge::find($refId),
            default => StoreItem::query()->where('type', RewardCatalogItem::STORE_ITEM_TYPES[$type])->find($refId),
        };

        if ($ref === null) {
            throw ValidationException::withMessages(['ref_id' => ['Pick an item of the chosen type.']]);
        }

        $name = match (true) {
            $ref instanceof StoreItem => $ref->name,
            default => $ref->name_en,
        };

        return RewardCatalogItem::firstOrCreate(
            ['handler_key' => $type, 'handler_ref_id' => $ref->id],
            ['name' => $name, 'is_active' => true],
        );
    }

    protected function clashError(Event $clash): JsonResponse
    {
        return ApiResponse::error(
            'VALIDATION_ERROR',
            "Another Weekly Star (“{$clash->title_en}”) is already published for overlapping dates — only one runs at a time.",
            ['clashing_event_id' => $clash->id],
            422,
        );
    }

    /** @return array<string, mixed> */
    protected function payload(Event $event): array
    {
        return [
            'id'          => $event->id,
            'uuid'        => $event->uuid,
            'title_en'    => $event->title_en,
            'title_hi'    => $event->title_hi,
            'description' => $event->description,
            'rules'       => $event->rules ?? [],
            'status'      => $event->status,
            'phase'       => $event->phase(),
            'starts_at'   => $event->starts_at->toIso8601ZuluString(),
            'ends_at'     => $event->ends_at->toIso8601ZuluString(),
            'weeks'       => $this->weeksOf($event),
        ];
    }

    /** @return array<string, mixed> */
    protected function giftPayload(Gift $gift): array
    {
        return [
            'id'            => $gift->id,
            'name'          => $gift->name_en,
            'price'         => (int) $gift->coin_price,
            'thumbnail_url' => $gift->thumbnail_url,
            'is_active'     => (bool) $gift->is_active,
        ];
    }
}
