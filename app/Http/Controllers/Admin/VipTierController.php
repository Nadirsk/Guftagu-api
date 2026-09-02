<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Store\GiftCatalogue;
use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\EntranceEffect;
use App\Models\Frame;
use App\Models\VipTier;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GFT-060 / GFT-061 — VIP tiers (A.6c) and the cosmetics they unlock (A.6d).
 *
 * Prices are integer **paise** throughout. A.6c says "set VIP 3 monthly to ₹999 and the
 * app shows ₹999" — storing 99900 and dividing for display is how that stays exact.
 */
class VipTierController extends Controller
{
    public function __construct(
        protected AuditLogger $audit,
        protected GiftCatalogue $catalogue,
    ) {
    }

    // ------------------------------------------------------------------ tiers

    public function index(Request $request): JsonResponse
    {
        $tiers = VipTier::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('level')
            ->get();

        return ApiResponse::success([
            'tiers' => $tiers->map(fn (VipTier $tier) => $this->payload($tier)),
            // The panel builds its privileges matrix from this rather than hardcoding a
            // list that would drift from what the app actually understands.
            'privilege_catalogue' => collect(VipTier::PRIVILEGES)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateTier($request);

        $tier = VipTier::create($data);

        $this->audit->log($request->user(), 'vip_tier.create', 'vip', VipTier::class, $tier->id, null, $data);

        return ApiResponse::success($this->payload($tier), 'VIP tier created', 201);
    }

    public function update(Request $request, VipTier $tier): JsonResponse
    {
        $data = $this->validateTier($request, false, $tier);

        $before = $tier->only(array_keys($data));
        $tier->fill($data)->save();

        // A tier change alters which gifts a user can see, so the app catalogue is stale.
        $this->catalogue->flush();

        $this->audit->log($request->user(), 'vip_tier.update', 'vip', VipTier::class, $tier->id, $before, $data);

        return ApiResponse::success($this->payload($tier->fresh()), 'VIP tier updated');
    }

    /**
     * Tiers are deactivated, not deleted: gifts, frames and effects point at them, and
     * subscriptions will reference them once purchases exist.
     */
    public function destroy(Request $request, VipTier $tier): JsonResponse
    {
        $tier->forceFill(['is_active' => false])->save();
        $this->catalogue->flush();

        $this->audit->log($request->user(), 'vip_tier.deactivate', 'vip', VipTier::class, $tier->id, ['is_active' => true], ['is_active' => false]);

        return ApiResponse::success(null, 'Tier deactivated — anything pointing at it keeps working');
    }

    // ------------------------------------------------------------- cosmetics

    public function cosmetics(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'frames' => Frame::query()->with('requiredVipTier:id,level')->orderBy('name')->get()
                ->map(fn (Frame $f) => [
                    'id'            => $f->id,
                    'name'          => $f->name,
                    'image_url'     => $f->image_url,
                    'animation_url' => $f->animation_url,
                    'source'        => $f->source,
                    'coin_price'    => $f->coin_price,
                    'vip_level'     => $f->requiredVipTier?->level,
                    'is_active'     => $f->is_active,
                ]),
            'badges' => Badge::query()->orderBy('name_en')->get()
                ->map(fn (Badge $b) => [
                    'id'              => $b->id,
                    'key'             => $b->key,
                    'name_en'         => $b->name_en,
                    'name_hi'         => $b->name_hi,
                    'icon_url'        => $b->icon_url,
                    'description'     => $b->description,
                    'is_auto_awarded' => $b->is_auto_awarded,
                    'is_active'       => $b->is_active,
                ]),
            'entrance_effects' => EntranceEffect::query()->with('requiredVipTier:id,level')->orderBy('name')->get()
                ->map(fn (EntranceEffect $e) => [
                    'id'                  => $e->id,
                    'name'                => $e->name,
                    'animation_url'       => $e->animation_url,
                    'animation_type'      => $e->animation_type,
                    'duration_ms'         => $e->duration_ms,
                    'trigger'             => $e->trigger,
                    'vip_level'           => $e->requiredVipTier?->level,
                    'min_gift_coin_value' => $e->min_gift_coin_value,
                    'is_active'           => $e->is_active,
                ]),
            'triggers' => EntranceEffect::TRIGGERS,
            'sources'  => Frame::SOURCES,
        ]);
    }

    public function storeFrame(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:80'],
            'image_url'            => ['sometimes', 'nullable', 'string', 'max:500'],
            'animation_url'        => ['sometimes', 'nullable', 'string', 'max:500'],
            'source'               => ['sometimes', Rule::in(Frame::SOURCES)],
            'coin_price'           => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'required_vip_tier_id' => ['sometimes', 'nullable', 'integer', Rule::exists('vip_tiers', 'id')],
            'is_active'            => ['sometimes', 'boolean'],
        ]);

        $frame = Frame::create($data);

        $this->audit->log($request->user(), 'frame.create', 'vip', Frame::class, $frame->id, null, $data);

        return ApiResponse::success(['id' => $frame->id], 'Frame created', 201);
    }

    public function updateFrame(Request $request, Frame $frame): JsonResponse
    {
        $data = $request->validate([
            'name'                 => ['sometimes', 'string', 'max:80'],
            'image_url'            => ['sometimes', 'nullable', 'string', 'max:500'],
            'animation_url'        => ['sometimes', 'nullable', 'string', 'max:500'],
            'source'               => ['sometimes', Rule::in(Frame::SOURCES)],
            'coin_price'           => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'required_vip_tier_id' => ['sometimes', 'nullable', 'integer', Rule::exists('vip_tiers', 'id')],
            'is_active'            => ['sometimes', 'boolean'],
        ]);

        $before = $frame->only(array_keys($data));
        $frame->fill($data)->save();

        $this->audit->log($request->user(), 'frame.update', 'vip', Frame::class, $frame->id, $before, $data);

        return ApiResponse::success(null, 'Frame updated');
    }

    public function storeBadge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'             => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('badges', 'key')],
            'name_en'         => ['required', 'string', 'max:80'],
            'name_hi'         => ['sometimes', 'nullable', 'string', 'max:80'],
            'icon_url'        => ['sometimes', 'nullable', 'string', 'max:500'],
            'description'     => ['sometimes', 'nullable', 'string', 'max:300'],
            'is_auto_awarded' => ['sometimes', 'boolean'],
            'is_active'       => ['sometimes', 'boolean'],
        ]);

        $badge = Badge::create($data);

        $this->audit->log($request->user(), 'badge.create', 'vip', Badge::class, $badge->id, null, $data);

        return ApiResponse::success(['id' => $badge->id], 'Badge created', 201);
    }

    public function storeEffect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:80'],
            'animation_url'        => ['sometimes', 'nullable', 'string', 'max:500'],
            'animation_type'       => ['sometimes', 'nullable', 'string', 'max:10'],
            'duration_ms'          => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60000'],
            'trigger'              => ['sometimes', Rule::in(EntranceEffect::TRIGGERS)],
            'required_vip_tier_id' => ['sometimes', 'nullable', 'integer', Rule::exists('vip_tiers', 'id')],
            'min_gift_coin_value'  => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active'            => ['sometimes', 'boolean'],
        ]);

        $effect = EntranceEffect::create($data);

        $this->audit->log($request->user(), 'entrance_effect.create', 'vip', EntranceEffect::class, $effect->id, null, $data);

        return ApiResponse::success(['id' => $effect->id], 'Entrance effect created', 201);
    }

    // ----------------------------------------------------------------- shared

    /** @return array<string, mixed> */
    protected function validateTier(Request $request, bool $creating = true, ?VipTier $tier = null): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'level' => [
                $creating ? 'required' : 'sometimes',
                'integer', 'min:1', 'max:20',
                Rule::unique('vip_tiers', 'level')->ignore($tier?->id),
            ],
            'name_en' => [$required, 'string', 'max:80'],
            'name_hi' => ['sometimes', 'nullable', 'string', 'max:80'],

            'badge_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'frame_url' => ['sometimes', 'nullable', 'string', 'max:500'],

            // Paise, as integers. `numeric` would accept 999.5 paise, which is not money.
            'monthly_price_paise'   => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'quarterly_price_paise' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'yearly_price_paise'    => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'coin_price'            => ['sometimes', 'integer', 'min:0', 'max:1000000000'],

            'privileges'   => ['sometimes', 'array'],
            'privileges.*' => ['string', Rule::in(array_keys(VipTier::PRIVILEGES))],

            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    protected function payload(VipTier $tier): array
    {
        return [
            'id'      => $tier->id,
            'level'   => $tier->level,
            'name_en' => $tier->name_en,
            'name_hi' => $tier->name_hi,

            'badge_url' => $tier->badge_url,
            'frame_url' => $tier->frame_url,

            'monthly_price_paise'   => $tier->monthly_price_paise,
            'quarterly_price_paise' => $tier->quarterly_price_paise,
            'yearly_price_paise'    => $tier->yearly_price_paise,
            'coin_price'            => $tier->coin_price,
            // Rupees are derived for display only; paise remain the stored truth.
            'monthly_rupees'        => $tier->monthlyRupees(),

            'privileges' => $tier->privileges ?? [],
            'is_active'  => $tier->is_active,
        ];
    }
}
