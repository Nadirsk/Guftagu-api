<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\RoomCategory;
use App\Models\RoomTheme;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GFT-040 / GFT-041 — the room category and theme catalogue (A.4d).
 *
 * This is the one part of A.4 that is useful before a single room exists: the app cannot
 * offer categories or themes that were never defined, so the catalogue has to come first.
 */
class RoomCatalogueController extends Controller
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    // ------------------------------------------------------------- categories

    public function categories(Request $request): JsonResponse
    {
        $categories = RoomCategory::query()
            ->withCount('rooms')
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name_en')
            ->get();

        return ApiResponse::success($categories->map(fn (RoomCategory $c) => [
            'id'         => $c->id,
            'key'        => $c->key,
            'name_en'    => $c->name_en,
            'name_hi'    => $c->name_hi,
            'icon_url'   => $c->icon_url,
            'sort_order' => $c->sort_order,
            'is_active'  => $c->is_active,
            'room_count' => $c->rooms_count,
        ])->all());
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'        => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('room_categories', 'key')],
            'name_en'    => ['required', 'string', 'max:80'],
            'name_hi'    => ['sometimes', 'nullable', 'string', 'max:80'],
            'icon_url'   => ['sometimes', 'nullable', 'url', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'is_active'  => ['sometimes', 'boolean'],
        ]);

        $category = RoomCategory::create($data);

        $this->audit->log($request->user(), 'room_category.create', 'rooms', RoomCategory::class, $category->id, null, $data);

        return ApiResponse::success(['id' => $category->id], 'Category created', 201);
    }

    public function updateCategory(Request $request, RoomCategory $category): JsonResponse
    {
        $data = $request->validate([
            'name_en'    => ['sometimes', 'string', 'max:80'],
            'name_hi'    => ['sometimes', 'nullable', 'string', 'max:80'],
            'icon_url'   => ['sometimes', 'nullable', 'url', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'is_active'  => ['sometimes', 'boolean'],
        ]);

        $before = $category->only(array_keys($data));
        $category->fill($data)->save();

        $this->audit->log($request->user(), 'room_category.update', 'rooms', RoomCategory::class, $category->id, $before, $data);

        return ApiResponse::success(null, 'Category updated');
    }

    public function destroyCategory(Request $request, RoomCategory $category): JsonResponse
    {
        $inUse = $category->rooms()->count();

        if ($inUse > 0) {
            // Deleting would orphan live rooms into an uncategorised state. Deactivating
            // hides it from the app while leaving history intact, which is what is wanted.
            return ApiResponse::error(
                'BAD_REQUEST',
                'Rooms still use this category. Deactivate it instead — that hides it from the app without breaking existing rooms.',
                ['room_count' => $inUse],
                400,
            );
        }

        $this->audit->log($request->user(), 'room_category.delete', 'rooms', RoomCategory::class, $category->id, ['key' => $category->key], null);

        $category->delete();

        return ApiResponse::success(null, 'Category deleted');
    }

    // ----------------------------------------------------------------- themes

    public function themes(Request $request): JsonResponse
    {
        $themes = RoomTheme::query()
            ->withCount('rooms')
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return ApiResponse::success($themes->map(fn (RoomTheme $t) => [
            'id'                   => $t->id,
            'name'                 => $t->name,
            'background_url'       => $t->background_url,
            'preview_url'          => $t->preview_url,
            'is_premium'           => $t->is_premium,
            'required_vip_tier_id' => $t->required_vip_tier_id,
            'coin_price'           => $t->coin_price,
            'is_active'            => $t->is_active,
            'room_count'           => $t->rooms_count,
        ])->all());
    }

    public function storeTheme(Request $request): JsonResponse
    {
        $data = $this->validateTheme($request);

        $theme = RoomTheme::create($data);

        $this->audit->log($request->user(), 'room_theme.create', 'rooms', RoomTheme::class, $theme->id, null, $data);

        return ApiResponse::success(['id' => $theme->id], 'Theme created', 201);
    }

    public function updateTheme(Request $request, RoomTheme $theme): JsonResponse
    {
        $data = $this->validateTheme($request, false);

        $before = $theme->only(array_keys($data));
        $theme->fill($data)->save();

        $this->audit->log($request->user(), 'room_theme.update', 'rooms', RoomTheme::class, $theme->id, $before, $data);

        return ApiResponse::success(null, 'Theme updated');
    }

    public function destroyTheme(Request $request, RoomTheme $theme): JsonResponse
    {
        $inUse = $theme->rooms()->count();

        if ($inUse > 0) {
            return ApiResponse::error(
                'BAD_REQUEST',
                'Rooms still use this theme. Deactivate it instead.',
                ['room_count' => $inUse],
                400,
            );
        }

        $this->audit->log($request->user(), 'room_theme.delete', 'rooms', RoomTheme::class, $theme->id, ['name' => $theme->name], null);

        $theme->delete();

        return ApiResponse::success(null, 'Theme deleted');
    }

    /** @return array<string, mixed> */
    protected function validateTheme(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name'                 => [$required, 'string', 'max:80'],
            'background_url'       => ['sometimes', 'nullable', 'url', 'max:500'],
            'preview_url'          => ['sometimes', 'nullable', 'url', 'max:500'],
            'is_premium'           => ['sometimes', 'boolean'],
            // Validated against vip_tiers now that A.6 has created it — and there is a
            // foreign key, so without this an unknown id would surface as a 500 instead
            // of a field error. Note this is a tier **id**, not a VIP level.
            'required_vip_tier_id' => ['sometimes', 'nullable', 'integer', Rule::exists('vip_tiers', 'id')],
            'coin_price'           => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'is_active'            => ['sometimes', 'boolean'],
        ]);
    }
}
