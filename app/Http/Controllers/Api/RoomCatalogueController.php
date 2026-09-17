<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoomCategory;
use App\Models\RoomSeatTemplate;
use App\Models\RoomTheme;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * D.2a — read-only reference data for the room-creation screen. The mobile counterpart of
 * {@see \App\Http\Controllers\Admin\RoomCatalogueController}, which manages this catalogue;
 * this one only ever reads it, so it carries no audit logger or upload dependency.
 */
class RoomCatalogueController extends Controller
{
    public function categories(): JsonResponse
    {
        $categories = RoomCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name_en')
            ->get();

        return ApiResponse::success($categories->map(fn (RoomCategory $c) => [
            'id'       => $c->id,
            'key'      => $c->key,
            'name_en'  => $c->name_en,
            'name_hi'  => $c->name_hi,
            'icon_url' => $c->icon_url,
        ])->all());
    }

    public function themes(): JsonResponse
    {
        $themes = RoomTheme::query()
            ->where('is_active', true)
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
        ])->all());
    }

    public function seatTemplates(): JsonResponse
    {
        $templates = RoomSeatTemplate::query()
            ->where('is_active', true)
            ->orderBy('total_seats')->orderBy('name')
            ->get();

        return ApiResponse::success($templates->map(fn (RoomSeatTemplate $t) => [
            'id'            => $t->id,
            'name'          => $t->name,
            'total_seats'   => $t->total_seats,
            'vip_positions' => $t->vip_positions ?? [],
        ])->all());
    }
}
