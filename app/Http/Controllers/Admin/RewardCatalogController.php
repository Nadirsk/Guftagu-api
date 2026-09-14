<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Events\EventCampaignService;
use App\Http\Controllers\Controller;
use App\Models\RewardCatalogItem;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The reward catalog behind every event's tier bundles — replaces the old fixed
 * `reward_type` enum. Adding a genuinely new kind of reward is a row here, not a code
 * change: pick an automated `handler_key` (coins, diamonds, vip, frame, chat_bubble,
 * entry_effect, badge) when one of InventoryService's grant paths applies, or `manual`
 * for anything else — a manual reward still shows in the app and still gets claimed, it
 * just records the claim for support to fulfil outside the system.
 */
class RewardCatalogController extends Controller
{
    public function __construct(
        protected EventCampaignService $campaigns,
        protected AuditLogger $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $items = RewardCatalogItem::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('handler_key')->orderBy('name')
            ->get();

        return ApiResponse::success([
            'items'              => $items->map(fn (RewardCatalogItem $i) => $this->campaigns->catalogPayload($i)),
            'handler_keys'       => RewardCatalogItem::HANDLER_KEYS,
            'automated_handlers' => RewardCatalogItem::AUTOMATED_HANDLERS,
            'store_item_types'   => RewardCatalogItem::STORE_ITEM_TYPES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateItem($request);

        $item = RewardCatalogItem::create($data);

        $this->audit->log($request->user(), 'reward_catalog.create', 'events', RewardCatalogItem::class, $item->id, null, $data);

        return ApiResponse::success($this->campaigns->catalogPayload($item), 'Reward added to the catalog', 201);
    }

    public function update(Request $request, RewardCatalogItem $rewardCatalogItem): JsonResponse
    {
        $data = $this->validateItem($request, false);

        $before = $rewardCatalogItem->only(array_keys($data));
        $rewardCatalogItem->fill($data)->save();

        $this->audit->log($request->user(), 'reward_catalog.update', 'events', RewardCatalogItem::class, $rewardCatalogItem->id, $before, $data);

        return ApiResponse::success($this->campaigns->catalogPayload($rewardCatalogItem->fresh()), 'Updated');
    }

    /** Deactivated, not deleted: event_tier_rewards restrict-deletes against this row on purpose. */
    public function destroy(Request $request, RewardCatalogItem $rewardCatalogItem): JsonResponse
    {
        if ($rewardCatalogItem->tierRewards()->exists()) {
            $rewardCatalogItem->forceFill(['is_active' => false])->save();

            $this->audit->log($request->user(), 'reward_catalog.deactivate', 'events', RewardCatalogItem::class, $rewardCatalogItem->id, null, ['is_active' => false]);

            return ApiResponse::success(null, 'In use by an event — deactivated instead of deleted');
        }

        $this->audit->log($request->user(), 'reward_catalog.delete', 'events', RewardCatalogItem::class, $rewardCatalogItem->id, ['name' => $rewardCatalogItem->name], null);
        $rewardCatalogItem->delete();

        return ApiResponse::success(null, 'Removed');
    }

    /** @return array<string, mixed> */
    protected function validateItem(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'name'            => [$required, 'string', 'max:100'],
            'icon_url'        => ['sometimes', 'nullable', 'string', 'max:500'],
            'description'     => ['sometimes', 'nullable', 'string', 'max:300'],
            'handler_key'     => [$required, Rule::in(RewardCatalogItem::HANDLER_KEYS)],
            'handler_ref_id'  => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active'       => ['sometimes', 'boolean'],
        ]);

        $needsRef = in_array($data['handler_key'] ?? null, ['vip', 'frame', 'chat_bubble', 'entry_effect', 'badge'], true);

        if ($needsRef && empty($data['handler_ref_id'])) {
            throw ValidationException::withMessages([
                'handler_ref_id' => ["A {$data['handler_key']} reward must reference which one — pick from the catalogue."],
            ]);
        }

        if (isset($data['handler_ref_id']) && $needsRef) {
            $this->assertRefExists($data['handler_key'], $data['handler_ref_id']);
        }

        return $data;
    }

    protected function assertRefExists(string $handlerKey, int $refId): void
    {
        $table = match ($handlerKey) {
            'vip' => 'vip_tiers',
            'badge' => 'badges',
            default => 'store_items',
        };

        $query = DB::table($table)->where('id', $refId);

        if (isset(RewardCatalogItem::STORE_ITEM_TYPES[$handlerKey])) {
            $query->where('type', RewardCatalogItem::STORE_ITEM_TYPES[$handlerKey]);
        }

        if (! $query->exists()) {
            throw ValidationException::withMessages([
                'handler_ref_id' => ["No matching {$handlerKey} catalogue row with that id."],
            ]);
        }
    }
}
