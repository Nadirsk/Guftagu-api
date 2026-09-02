<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Events\EventException;
use App\Domain\Events\EventService;
use App\Domain\Events\LuckyDrawService;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventReward;
use App\Models\LuckyDraw;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Epic A.9a/b — events, tournaments and lucky draws. docs/03 §12.
 */
class EventController extends Controller
{
    public function __construct(
        protected EventService $events,
        protected LuckyDrawService $draws,
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
        ];
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
