<?php

namespace App\Http\Controllers\Api;

use App\Domain\Store\InventoryService;
use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\UserBadge;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * D.7c — badges ("Medals"). Deliberately just a list of what exists and what this user
 * already holds: an auto-tracking "earn this by doing X" achievements engine is a
 * separate, larger feature that was explicitly deferred pending the app's badge UI design.
 */
class BadgeController extends Controller
{
    public function __construct(protected InventoryService $inventory)
    {
    }

    /** GET /badges */
    public function index(Request $request): JsonResponse
    {
        $owned = $this->inventory->activeBadges($request->user())->keyBy('badge_id');

        $badges = Badge::query()->where('is_active', true)->get();

        return ApiResponse::success($badges->map(function (Badge $b) use ($owned) {
            /** @var UserBadge|null $mine */
            $mine = $owned->get($b->id);

            return [
                'id'          => $b->id,
                'key'         => $b->key,
                'name_en'     => $b->name_en,
                'name_hi'     => $b->name_hi,
                'icon_url'    => $b->icon_url,
                'description' => $b->description,
                'is_owned'    => $mine !== null,
                'awarded_at'  => $mine?->awarded_at?->toIso8601ZuluString(),
                'expires_at'  => $mine?->expires_at?->toIso8601ZuluString(),
            ];
        })->all());
    }
}
