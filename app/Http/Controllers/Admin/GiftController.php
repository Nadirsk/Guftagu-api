<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Media\ImageUploadService;
use App\Domain\Store\GiftCatalogue;
use App\Http\Controllers\Controller;
use App\Models\Gift;
use App\Models\GiftCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GFT-056 / GFT-057 / GFT-058 / GFT-059 — the gift catalogue (A.6a, A.6b).
 *
 * Every write flushes the app-facing cache, so a change is visible immediately rather
 * than after the 600 s TTL.
 */
class GiftController extends Controller
{
    /** A.6a — "a 60 MB animation is rejected with a clear size error". */
    public const MAX_ANIMATION_KB = 10240;   // 10 MB, matching docs/01 §6's image cap
    public const MAX_IMAGE_KB = 5120;        // 5 MB — a thumbnail or icon, not an animation

    public function __construct(
        protected GiftCatalogue $catalogue,
        protected AuditLogger $audit,
        protected ImageUploadService $uploads,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'        => ['sometimes', 'nullable', 'string', 'max:80'],
            'category' => ['sometimes', 'nullable', 'integer'],
            'tier'     => ['sometimes', 'nullable', Rule::in(Gift::TIERS)],
            'state'    => ['sometimes', 'nullable', Rule::in(['available', 'sold_out', 'scheduled', 'inactive'])],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Gift::query()
            ->with(['category:id,key,name_en', 'requiredVipTier:id,level,name_en'])
            ->when($data['q'] ?? null, fn ($q, string $term) => $q->where(function ($inner) use ($term) {
                $inner->where('name_en', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%");
            }))
            ->when($data['category'] ?? null, fn ($q, int $c) => $q->where('category_id', $c))
            ->when($data['tier'] ?? null, fn ($q, string $t) => $q->where('tier', $t))
            ->when($data['state'] ?? null, function ($q, string $state) {
                return match ($state) {
                    'available' => $q->available(),
                    'sold_out'  => $q->where('is_limited', true)->where('stock', '<=', 0),
                    'scheduled' => $q->whereNotNull('available_from')->where('available_from', '>', now()),
                    'inactive'  => $q->where('is_active', false),
                };
            })
            ->orderBy('sort_order')->orderBy('coin_price');

        $paginator = $query->paginate(
            perPage: (int) ($data['per_page'] ?? 30),
            page: (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (Gift $gift) => $this->payload($gift)
        )->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateGift($request);

        $gift = Gift::create($data);
        $this->catalogue->flush();

        $this->audit->log($request->user(), 'gift.create', 'gifts', Gift::class, $gift->id, null, $data);

        return ApiResponse::success($this->payload($gift), 'Gift created', 201);
    }

    public function update(Request $request, Gift $gift): JsonResponse
    {
        $data = $this->validateGift($request, false, $gift);

        $before = $gift->only(array_keys($data));
        $gift->fill($data)->save();
        $this->catalogue->flush();

        $this->audit->log($request->user(), 'gift.update', 'gifts', Gift::class, $gift->id, $before, $data);

        return ApiResponse::success($this->payload($gift->fresh()), 'Gift updated');
    }

    /**
     * Gifts are never deleted — a sent gift references one, and the history has to stay
     * readable. Deactivating removes it from the app, which is the actual intent.
     */
    public function destroy(Request $request, Gift $gift): JsonResponse
    {
        $gift->forceFill(['is_active' => false])->save();
        $this->catalogue->flush();

        $this->audit->log($request->user(), 'gift.deactivate', 'gifts', Gift::class, $gift->id, ['is_active' => true], ['is_active' => false]);

        return ApiResponse::success(null, 'Gift deactivated — past sends keep referencing it');
    }

    /** GFT-059 — top a limited drop back up, or take it off sale. */
    public function restock(Request $request, Gift $gift): JsonResponse
    {
        $data = $request->validate([
            'stock' => ['required', 'integer', 'min:0', 'max:100000000'],
        ]);

        if (! $gift->is_limited) {
            return ApiResponse::error(
                'BAD_REQUEST',
                'That gift is not a limited drop, so it has no stock to set.',
                null,
                400,
            );
        }

        $before = ['stock' => $gift->stock];
        $gift->forceFill(['stock' => (int) $data['stock']])->save();
        $this->catalogue->flush();

        $this->audit->log($request->user(), 'gift.restock', 'gifts', Gift::class, $gift->id, $before, ['stock' => (int) $data['stock']]);

        return ApiResponse::success(['stock' => $gift->stock], 'Stock updated');
    }

    /**
     * GFT-057 — animation upload.
     *
     * Writes through `ImageUploadService`, so it lands on the local `public` disk until
     * `UPLOADS_DISK=vultr` is set (config/filesystems.php) — the validation is the part
     * that matters either way.
     */
    public function uploadAnimation(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                // A.6a wants a clear size error, so the cap is stated in the rule rather
                // than left to PHP's upload_max_filesize producing an empty request.
                'max:'.self::MAX_ANIMATION_KB,
                'mimetypes:application/json,application/octet-stream,video/mp4,text/plain',
            ],
            'type' => ['required', Rule::in(Gift::ANIMATION_TYPES)],
        ], [
            'file.max' => 'That file is larger than '.(self::MAX_ANIMATION_KB / 1024).' MB. Compress it or shorten the animation.',
        ]);

        $result = $this->uploads->store($request->file('file'), 'gift-animations');

        return ApiResponse::success(
            [...$result, 'type' => $request->input('type')],
            'Animation uploaded',
        );
    }

    /** Thumbnail upload — the still image shown on the gift card, separate from the animation. */
    /**
     * `id` is optional: creating a gift has none yet, so the panel just holds the
     * returned URL until Save. Editing one has an id, and passing it here saves the
     * thumbnail immediately — one step instead of upload-then-remember-to-Save.
     */
    public function uploadThumbnail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => [
                'required', 'file', 'max:'.self::MAX_IMAGE_KB,
                'mimes:jpg,jpeg,png,webp,gif',
            ],
            'id' => ['sometimes', 'nullable', 'integer', Rule::exists('gifts', 'id')],
        ], [
            'file.max' => 'That image is larger than '.(self::MAX_IMAGE_KB / 1024).' MB.',
        ]);

        $result = $this->uploads->store($request->file('file'), 'gift-thumbnails');

        if (! empty($data['id'])) {
            $gift = Gift::findOrFail($data['id']);
            $before = ['thumbnail_url' => $gift->thumbnail_url];

            $gift->forceFill(['thumbnail_url' => $result['url']])->save();
            $this->catalogue->flush();

            $this->audit->log($request->user(), 'gift.update', 'gifts', Gift::class, $gift->id, $before, ['thumbnail_url' => $result['url']]);

            $result['gift'] = $this->payload($gift->fresh());
        }

        return ApiResponse::success($result, 'Thumbnail uploaded');
    }

    // ------------------------------------------------------------- categories

    /** Category icon upload — same `id` behaviour as {@see uploadThumbnail()}. */
    public function uploadCategoryIcon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => [
                'required', 'file', 'max:'.self::MAX_IMAGE_KB,
                'mimes:jpg,jpeg,png,webp,gif',
            ],
            'id' => ['sometimes', 'nullable', 'integer', Rule::exists('gift_categories', 'id')],
        ], [
            'file.max' => 'That image is larger than '.(self::MAX_IMAGE_KB / 1024).' MB.',
        ]);

        $result = $this->uploads->store($request->file('file'), 'gift-category-icons');

        if (! empty($data['id'])) {
            $category = GiftCategory::findOrFail($data['id']);
            $before = ['icon_url' => $category->icon_url];

            $category->forceFill(['icon_url' => $result['url']])->save();
            $this->catalogue->flush();

            $this->audit->log($request->user(), 'gift_category.update', 'gifts', GiftCategory::class, $category->id, $before, ['icon_url' => $result['url']]);

            $result['category'] = [
                'id' => $category->id, 'key' => $category->key, 'name_en' => $category->name_en,
                'name_hi' => $category->name_hi, 'icon_url' => $category->icon_url,
                'sort_order' => $category->sort_order, 'is_active' => $category->is_active,
                'gift_count' => $category->gifts()->count(),
            ];
        }

        return ApiResponse::success($result, 'Icon uploaded');
    }

    public function categories(Request $request): JsonResponse
    {
        $categories = GiftCategory::query()
            ->withCount('gifts')
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name_en')
            ->get();

        return ApiResponse::success($categories->map(fn (GiftCategory $c) => [
            'id'         => $c->id,
            'key'        => $c->key,
            'name_en'    => $c->name_en,
            'name_hi'    => $c->name_hi,
            'icon_url'   => $c->icon_url,
            'sort_order' => $c->sort_order,
            'is_active'  => $c->is_active,
            'gift_count' => $c->gifts_count,
        ])->all());
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'        => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('gift_categories', 'key')],
            'name_en'    => ['required', 'string', 'max:80'],
            'name_hi'    => ['sometimes', 'nullable', 'string', 'max:80'],
            'icon_url'   => ['sometimes', 'nullable', 'string', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'is_active'  => ['sometimes', 'boolean'],
        ]);

        $category = GiftCategory::create($data);
        $this->catalogue->flush();

        $this->audit->log($request->user(), 'gift_category.create', 'gifts', GiftCategory::class, $category->id, null, $data);

        return ApiResponse::success(['id' => $category->id], 'Category created', 201);
    }

    public function updateCategory(Request $request, GiftCategory $category): JsonResponse
    {
        $data = $request->validate([
            'name_en'    => ['sometimes', 'string', 'max:80'],
            'name_hi'    => ['sometimes', 'nullable', 'string', 'max:80'],
            'icon_url'   => ['sometimes', 'nullable', 'string', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'is_active'  => ['sometimes', 'boolean'],
        ]);

        $before = $category->only(array_keys($data));
        $category->fill($data)->save();
        $this->catalogue->flush();

        $this->audit->log($request->user(), 'gift_category.update', 'gifts', GiftCategory::class, $category->id, $before, $data);

        return ApiResponse::success(null, 'Category updated');
    }

    // ----------------------------------------------------------------- shared

    /** @return array<string, mixed> */
    protected function validateGift(Request $request, bool $creating = true, ?Gift $gift = null): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'code' => [
                $creating ? 'required' : 'sometimes',
                'string', 'max:50', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('gifts', 'code')->ignore($gift?->id),
            ],
            'name_en'       => [$required, 'string', 'max:80'],
            'name_hi'       => ['sometimes', 'nullable', 'string', 'max:80'],
            'category_id'   => ['sometimes', 'nullable', 'integer', Rule::exists('gift_categories', 'id')],
            'tier'          => ['sometimes', Rule::in(Gift::TIERS)],
            // Integers only — a gift priced 99.5 coins is not representable (docs/02 §15).
            'coin_price'    => [$required, 'integer', 'min:1', 'max:100000000'],
            'diamond_value' => [$required, 'integer', 'min:0', 'max:100000000'],

            'thumbnail_url'  => ['sometimes', 'nullable', 'string', 'max:500'],
            'animation_url'  => ['sometimes', 'nullable', 'string', 'max:500'],
            'animation_type' => ['sometimes', 'nullable', Rule::in(Gift::ANIMATION_TYPES)],
            'duration_ms'    => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60000'],
            'is_fullscreen'  => ['sometimes', 'boolean'],

            'is_combo_enabled'     => ['sometimes', 'boolean'],
            'max_combo'            => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'required_vip_tier_id' => ['sometimes', 'nullable', 'integer', Rule::exists('vip_tiers', 'id')],

            'is_limited'     => ['sometimes', 'boolean'],
            'stock'          => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'available_from' => ['sometimes', 'nullable', 'date'],
            'available_to'   => ['sometimes', 'nullable', 'date', 'after:available_from'],

            'is_active'  => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);

        // A limited drop with no stock number is a contradiction: `available()` treats
        // NULL as unlimited, so it would never sell out.
        if (($data['is_limited'] ?? $gift?->is_limited) && ! array_key_exists('stock', $data) && $gift?->stock === null) {
            $data['stock'] = 0;
        }

        // ... and an unlimited gift must not carry a stock number, or it will sell out.
        if (array_key_exists('is_limited', $data) && $data['is_limited'] === false) {
            $data['stock'] = null;
        }

        return $data;
    }

    protected function payload(Gift $gift): array
    {
        return [
            'id'             => $gift->id,
            'code'           => $gift->code,
            'name_en'        => $gift->name_en,
            'name_hi'        => $gift->name_hi,
            'category'       => $gift->category === null ? null : [
                'id' => $gift->category->id, 'key' => $gift->category->key, 'name' => $gift->category->name_en,
            ],
            'tier'           => $gift->tier,
            'coin_price'     => $gift->coin_price,
            'diamond_value'  => $gift->diamond_value,
            'thumbnail_url'  => $gift->thumbnail_url,
            'animation_url'  => $gift->animation_url,
            'animation_type' => $gift->animation_type,
            'duration_ms'    => $gift->duration_ms,
            'is_fullscreen'  => $gift->is_fullscreen,
            'max_combo'      => $gift->is_combo_enabled ? $gift->max_combo : 1,
            'vip_tier'       => $gift->requiredVipTier === null ? null : [
                'id' => $gift->requiredVipTier->id, 'level' => $gift->requiredVipTier->level,
            ],
            'is_limited'     => $gift->is_limited,
            'stock'          => $gift->stock,
            'available_from' => $gift->available_from?->toIso8601ZuluString(),
            'available_to'   => $gift->available_to?->toIso8601ZuluString(),
            'is_active'      => $gift->is_active,
            'sort_order'     => $gift->sort_order,
            // The three reasons a gift might not be sellable, reported separately so the
            // panel can say which one applies instead of a bare "unavailable".
            'state' => [
                'available'   => $gift->isAvailable(),
                'sold_out'    => $gift->isSoldOut(),
                'in_window'   => $gift->isWithinWindow(),
            ],
        ];
    }
}
