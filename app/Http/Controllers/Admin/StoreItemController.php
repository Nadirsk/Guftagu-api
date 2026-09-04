<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Media\ImageUploadService;
use App\Http\Controllers\Controller;
use App\Models\StoreItem;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The app's "Mall" / store catalogue — frames, bubbles, entry banners and entrance
 * effects. See `StoreItem` for why these four share one table instead of four.
 *
 * There is no purchase/ownership endpoint here yet (that needs a "backpack"-style table
 * plus wallet-deduction logic — a money-movement feature deliberately scoped separately).
 * This controller only manages the catalogue an eventual purchase flow would sell from.
 */
class StoreItemController extends Controller
{
    public const MAX_IMAGE_KB = 5120;

    public function __construct(
        protected AuditLogger $audit,
        protected ImageUploadService $uploads,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        // `include_inactive` is read via `$request->boolean()` below, not validated here —
        // a query string is never a real boolean (`?include_inactive=true` arrives as the
        // literal string "true"), and Laravel's `boolean` rule only accepts 0/1/"0"/"1".
        $data = $request->validate([
            'type' => ['sometimes', 'nullable', Rule::in(StoreItem::TYPES)],
        ]);

        $items = StoreItem::query()
            ->with('requiredVipTier:id,level')
            ->when($data['type'] ?? null, fn ($q, $type) => $q->ofType($type))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('type')->orderBy('name')
            ->get();

        return ApiResponse::success([
            'items'    => $items->map(fn (StoreItem $i) => $this->payload($i)),
            'types'    => StoreItem::TYPES,
            'sources'  => StoreItem::SOURCES,
            'triggers' => StoreItem::TRIGGERS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateItem($request);

        $item = StoreItem::create($data)->refresh();

        $this->audit->log($request->user(), 'store_item.create', 'vip', StoreItem::class, $item->id, null, $data);

        return ApiResponse::success($this->payload($item), 'Item created', 201);
    }

    public function update(Request $request, StoreItem $storeItem): JsonResponse
    {
        $data = $this->validateItem($request, false, $storeItem);

        $before = $storeItem->only(array_keys($data));
        $storeItem->fill($data)->save();

        $this->audit->log($request->user(), 'store_item.update', 'vip', StoreItem::class, $storeItem->id, $before, $data);

        return ApiResponse::success($this->payload($storeItem->fresh()), 'Item updated');
    }

    public function destroy(Request $request, StoreItem $storeItem): JsonResponse
    {
        $this->audit->log($request->user(), 'store_item.delete', 'vip', StoreItem::class, $storeItem->id, ['name' => $storeItem->name, 'type' => $storeItem->type], null);

        $storeItem->delete();

        return ApiResponse::success(null, 'Item deleted');
    }

    /** Image upload — same `id` behaviour as gifts' thumbnail upload (see GiftController). */
    public function uploadImage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => [
                'required', 'file', 'max:'.self::MAX_IMAGE_KB,
                'mimes:jpg,jpeg,png,webp,gif',
            ],
            'id' => ['sometimes', 'nullable', 'integer', Rule::exists('store_items', 'id')],
        ], [
            'file.max' => 'That image is larger than '.(self::MAX_IMAGE_KB / 1024).' MB.',
        ]);

        $result = $this->uploads->store($request->file('file'), 'store-items');

        if (! empty($data['id'])) {
            $item = StoreItem::findOrFail($data['id']);
            $before = ['image_url' => $item->image_url];

            $item->forceFill(['image_url' => $result['url']])->save();

            $this->audit->log($request->user(), 'store_item.update', 'vip', StoreItem::class, $item->id, $before, ['image_url' => $result['url']]);
        }

        return ApiResponse::success($result, 'Image uploaded');
    }

    /** @return array<string, mixed> */
    protected function validateItem(Request $request, bool $creating = true, ?StoreItem $item = null): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'type'                 => [$creating ? 'required' : 'prohibited', Rule::in(StoreItem::TYPES)],
            'name'                 => [$required, 'string', 'max:80'],
            'image_url'            => ['sometimes', 'nullable', 'string', 'max:500'],
            'animation_url'        => ['sometimes', 'nullable', 'string', 'max:500'],
            'animation_type'       => ['sometimes', 'nullable', 'string', 'max:10'],
            'duration_ms'          => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60000'],
            'trigger'              => ['sometimes', 'nullable', Rule::in(StoreItem::TRIGGERS)],
            'min_gift_coin_value'  => ['sometimes', 'nullable', 'integer', 'min:0'],
            'source'               => ['sometimes', 'nullable', Rule::in(StoreItem::SOURCES)],
            'coin_price'           => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'rental_days'          => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'required_vip_tier_id' => ['sometimes', 'nullable', 'integer', Rule::exists('vip_tiers', 'id')],
            'is_active'            => ['sometimes', 'boolean'],
        ]);
    }

    protected function payload(StoreItem $item): array
    {
        return [
            'id'                  => $item->id,
            'type'                => $item->type,
            'name'                => $item->name,
            'image_url'           => $item->image_url,
            'animation_url'       => $item->animation_url,
            'animation_type'      => $item->animation_type,
            'duration_ms'         => $item->duration_ms,
            'trigger'             => $item->trigger,
            'min_gift_coin_value' => $item->min_gift_coin_value,
            'source'              => $item->source,
            'coin_price'          => $item->coin_price,
            'rental_days'         => $item->rental_days,
            'required_vip_tier_id' => $item->required_vip_tier_id,
            'vip_level'           => $item->requiredVipTier?->level,
            'is_active'           => $item->is_active,
        ];
    }
}
