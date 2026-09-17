<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gift;
use App\Models\GiftCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** D.6b — the gift catalogue. */
class GiftController extends Controller
{
    /** GET /gifts — `?category=&tier=`. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['sometimes', 'nullable', 'integer'],
            'tier'     => ['sometimes', 'nullable', 'string', 'in:'.implode(',', Gift::TIERS)],
        ]);

        $gifts = Gift::query()
            ->available()
            ->when($data['category'] ?? null, fn ($q, int $c) => $q->where('category_id', $c))
            ->when($data['tier'] ?? null, fn ($q, string $t) => $q->where('tier', $t))
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success($gifts->map(fn (Gift $g) => [
            'id'               => $g->id,
            'code'             => $g->code,
            'name_en'          => $g->name_en,
            'name_hi'          => $g->name_hi,
            'tier'             => $g->tier,
            'coin_price'       => $g->coin_price,
            'diamond_value'    => $g->diamond_value,
            'thumbnail_url'    => $g->thumbnail_url,
            'animation_url'    => $g->animation_url,
            'animation_type'   => $g->animation_type,
            'duration_ms'      => $g->duration_ms,
            'is_fullscreen'    => $g->is_fullscreen,
            'is_combo_enabled' => $g->is_combo_enabled,
            'max_combo'        => $g->max_combo,
            'required_vip_tier_id' => $g->required_vip_tier_id,
            'category_id'      => $g->category_id,
        ])->all());
    }

    /** GET /gifts/categories */
    public function categories(): JsonResponse
    {
        $categories = GiftCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success($categories->map(fn (GiftCategory $c) => [
            'id'       => $c->id,
            'key'      => $c->key,
            'name_en'  => $c->name_en,
            'name_hi'  => $c->name_hi,
            'icon_url' => $c->icon_url,
        ])->all());
    }
}
