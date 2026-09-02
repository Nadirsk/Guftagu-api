<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Cms\AudienceBuilder;
use App\Domain\Cms\BroadcastService;
use App\Http\Controllers\Controller;
use App\Models\Broadcast;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GFT-105 — broadcast campaigns (A.10a). docs/03 §14.
 */
class BroadcastController extends Controller
{
    public function __construct(
        protected BroadcastService $broadcasts,
        protected AudienceBuilder $audience,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status'   => ['sometimes', 'nullable', 'string', 'max:20'],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = Broadcast::query()
            ->with(['creator:id,name', 'approver:id,name'])
            ->when($data['status'] ?? null, fn ($q, string $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate(
                perPage: (int) ($data['per_page'] ?? 25),
                page: (int) ($data['page'] ?? 1),
            );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (Broadcast $b) => $this->payload($b)
        )->all());
    }

    public function show(Broadcast $broadcast): JsonResponse
    {
        $broadcast->load(['creator:id,name', 'approver:id,name']);

        return ApiResponse::success([
            'broadcast' => $this->payload($broadcast) + [
                'audience_filter' => $broadcast->audience_filter,
            ],
            // A sent campaign shows the audience it went to, frozen. An unsent one shows
            // what it would reach if sent right now — those are different questions.
            'preview' => $broadcast->isSent() ? null : $this->broadcasts->preview($broadcast),
            'filters' => AudienceBuilder::FILTERS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->rules($request);

        $broadcast = $this->broadcasts->create($data, $request->user());

        return ApiResponse::success($this->payload($broadcast), 'Campaign created', 201);
    }

    public function update(Request $request, Broadcast $broadcast): JsonResponse
    {
        $data = $this->rules($request, $broadcast);

        $this->broadcasts->update($broadcast, $data, $request->user());

        return ApiResponse::success($this->payload($broadcast->fresh()), 'Campaign updated');
    }

    /**
     * POST /admin/broadcasts/preview — size an audience before anything exists.
     *
     * A.10a requires the count before sending, and an operator wants it while still
     * choosing filters, not only after saving a draft.
     */
    public function previewAudience(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience'        => ['required', Rule::in(Broadcast::AUDIENCES)],
            'audience_filter' => ['sometimes', 'nullable', 'array'],
            'user_ids'        => ['sometimes', 'nullable', 'array', 'max:5000'],
            'user_ids.*'      => ['integer'],
            'channels'        => ['sometimes', 'array'],
            'channels.*'      => [Rule::in(Broadcast::CHANNELS)],
        ]);

        $result = $this->audience->preview(
            $data['audience'],
            $data['audience_filter'] ?? [],
            $data['user_ids'] ?? [],
            $data['channels'] ?? ['push'],
        );

        return ApiResponse::success($result + [
            'sample' => $this->audience->sample(
                $data['audience'],
                $data['audience_filter'] ?? [],
                $data['user_ids'] ?? [],
            ),
        ]);
    }

    public function send(Request $request, Broadcast $broadcast): JsonResponse
    {
        $result = $this->broadcasts->send($broadcast, $request->user());

        return ApiResponse::success($result, 'Campaign sent');
    }

    /** GET /admin/broadcasts/{broadcast}/outcome — B.5b. */
    public function outcome(Request $request, Broadcast $broadcast): JsonResponse
    {
        $hours = (int) ($request->validate([
            'window_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
        ])['window_hours'] ?? 72);

        return ApiResponse::success($this->broadcasts->outcome($broadcast, $hours));
    }

    public function cancel(Request $request, Broadcast $broadcast): JsonResponse
    {
        $this->broadcasts->cancel($broadcast, $request->user());

        return ApiResponse::success(['status' => $broadcast->fresh()->status], 'Campaign cancelled');
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(Request $request, ?Broadcast $broadcast = null): array
    {
        $required = $broadcast === null ? 'required' : 'sometimes';

        return $request->validate([
            'title'           => [$required, 'string', 'max:200'],
            'body'            => [$required, 'string', 'max:2000'],
            'image_url'       => ['sometimes', 'nullable', 'url', 'max:500'],
            'deep_link'       => ['sometimes', 'nullable', 'string', 'max:500'],
            'audience'        => ['sometimes', Rule::in(Broadcast::AUDIENCES)],
            'audience_filter' => ['sometimes', 'nullable', 'array'],
            'user_ids'        => ['sometimes', 'nullable', 'array', 'max:5000'],
            'user_ids.*'      => ['integer'],
            'channels'        => ['sometimes', 'array', 'min:1'],
            'channels.*'      => [Rule::in(Broadcast::CHANNELS)],
            'scheduled_at'    => ['sometimes', 'nullable', 'date'],
        ]);
    }

    protected function payload(Broadcast $broadcast): array
    {
        return [
            'id'              => $broadcast->id,
            'uuid'            => $broadcast->uuid,
            'title'           => $broadcast->title,
            'body'            => $broadcast->body,
            'image_url'       => $broadcast->image_url,
            'deep_link'       => $broadcast->deep_link,
            'audience'        => $broadcast->audience,
            'channels'        => $broadcast->channels ?: [],
            // Frozen at send time — what the audience actually was, not what it is now.
            'audience_count'  => $broadcast->audience_count,
            'scheduled_at'    => $broadcast->scheduled_at?->toIso8601ZuluString(),
            'status'          => $broadcast->status,
            'is_editable'     => $broadcast->isEditable(),
            'sent_count'      => $broadcast->sent_count,
            'delivered_count' => $broadcast->delivered_count,
            'opened_count'    => $broadcast->opened_count,
            // Null rather than 0% — nothing has reported back, which is not the same as
            // nothing having arrived.
            'delivery_rate'   => $broadcast->deliveryRate(),
            'open_rate'       => $broadcast->openRate(),
            'sent_at'         => $broadcast->sent_at?->toIso8601ZuluString(),
            'created_by'      => $broadcast->creator?->name,
            'approved_by'     => $broadcast->approver?->name,
            'stats_note'      => $broadcast->isSent()
                ? 'Delivered and opened stay at zero until the app reports receipts back — that arrives with E.2c.'
                : null,
        ];
    }
}
