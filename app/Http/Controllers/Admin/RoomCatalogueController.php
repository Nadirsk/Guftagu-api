<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Media\ImageUploadService;
use App\Domain\Rooms\RoomException;
use App\Http\Controllers\Controller;
use App\Models\RoomCategory;
use App\Models\RoomSeatTemplate;
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
    public const MAX_IMAGE_KB = 5120; // 5 MB, matching gifts' image cap

    public function __construct(
        protected AuditLogger $audit,
        protected ImageUploadService $uploads,
    ) {
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

    /** Background image upload — same `id` behaviour as gifts' thumbnail upload (see GiftController). */
    public function uploadThemeBackground(Request $request): JsonResponse
    {
        return $this->uploadThemeImage($request, 'background_url', 'room-theme-backgrounds');
    }

    /** Preview image upload — the thumbnail shown in the catalogue and theme picker. */
    public function uploadThemePreview(Request $request): JsonResponse
    {
        return $this->uploadThemeImage($request, 'preview_url', 'room-theme-previews');
    }

    protected function uploadThemeImage(Request $request, string $column, string $folder): JsonResponse
    {
        $data = $request->validate([
            'file' => [
                'required', 'file', 'max:'.self::MAX_IMAGE_KB,
                'mimes:jpg,jpeg,png,webp,gif',
            ],
            'id' => ['sometimes', 'nullable', 'integer', Rule::exists('room_themes', 'id')],
        ], [
            'file.max' => 'That image is larger than '.(self::MAX_IMAGE_KB / 1024).' MB.',
        ]);

        $result = $this->uploads->store($request->file('file'), $folder);

        if (! empty($data['id'])) {
            $theme = RoomTheme::findOrFail($data['id']);
            $before = [$column => $theme->{$column}];

            $theme->forceFill([$column => $result['url']])->save();

            $this->audit->log($request->user(), 'room_theme.update', 'rooms', RoomTheme::class, $theme->id, $before, [$column => $result['url']]);
        }

        return ApiResponse::success($result, 'Image uploaded');
    }

    // ------------------------------------------------------------- seat templates

    /**
     * Reusable "N seats, these ones VIP" layouts. See the creating migration for why
     * this is a catalogue rather than something typed per room — a room's own
     * `seat_count` is unaffected either way, it just has ready-made options to offer.
     */
    public function seatTemplates(Request $request): JsonResponse
    {
        $templates = RoomSeatTemplate::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('total_seats')->orderBy('name')
            ->get();

        return ApiResponse::success($templates->map(fn (RoomSeatTemplate $t) => $this->seatTemplatePayload($t))->all());
    }

    public function storeSeatTemplate(Request $request): JsonResponse
    {
        $data = $this->validateSeatTemplate($request);
        $this->assertPositionsFitTotal($data['total_seats'], $data['vip_positions'] ?? []);

        $template = RoomSeatTemplate::create($data);

        $this->audit->log($request->user(), 'room_seat_template.create', 'rooms', RoomSeatTemplate::class, $template->id, null, $data);

        return ApiResponse::success($this->seatTemplatePayload($template), 'Seat template created', 201);
    }

    public function updateSeatTemplate(Request $request, RoomSeatTemplate $template): JsonResponse
    {
        $data = $this->validateSeatTemplate($request, false, $template);

        // Shrinking total_seats without also resending vip_positions must not leave a
        // stale position pointing past the new total.
        $effectiveTotal = $data['total_seats'] ?? $template->total_seats;
        $effectivePositions = $data['vip_positions'] ?? $template->vip_positions ?? [];
        $this->assertPositionsFitTotal($effectiveTotal, $effectivePositions);

        $before = $template->only(array_keys($data));
        $template->fill($data)->save();

        $this->audit->log($request->user(), 'room_seat_template.update', 'rooms', RoomSeatTemplate::class, $template->id, $before, $data);

        return ApiResponse::success($this->seatTemplatePayload($template->fresh()), 'Seat template updated');
    }

    public function destroySeatTemplate(Request $request, RoomSeatTemplate $template): JsonResponse
    {
        // Nothing references a template yet (no room-creation flow reads it), so unlike a
        // category or theme there is no "still in use" case to guard against here.
        $this->audit->log($request->user(), 'room_seat_template.delete', 'rooms', RoomSeatTemplate::class, $template->id, ['name' => $template->name], null);

        $template->delete();

        return ApiResponse::success(null, 'Seat template deleted');
    }

    /** @return array<string, mixed> */
    protected function validateSeatTemplate(Request $request, bool $creating = true, ?RoomSeatTemplate $template = null): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $totalSeats = $request->input('total_seats', $template?->total_seats);

        return $request->validate([
            'name'                => [$required, 'string', 'max:80'],
            'total_seats'         => [$required, 'integer', 'min:2', 'max:50'],
            'vip_positions'       => ['sometimes', 'nullable', 'array'],
            'vip_positions.*'     => ['integer', 'min:1', 'max:'.($totalSeats ?? 50), 'distinct'],
            'is_active'           => ['sometimes', 'boolean'],
        ], [
            'vip_positions.*.max' => 'A VIP position cannot be higher than the total seat count.',
            'vip_positions.*.distinct' => 'The same seat position is listed more than once.',
        ]);
    }

    /** @param int[] $positions */
    protected function assertPositionsFitTotal(int $totalSeats, array $positions): void
    {
        foreach ($positions as $position) {
            if ($position > $totalSeats) {
                throw new RoomException(
                    'VALIDATION_ERROR',
                    "Position {$position} is past the {$totalSeats}-seat total.",
                );
            }
        }
    }

    protected function seatTemplatePayload(RoomSeatTemplate $template): array
    {
        return [
            'id'            => $template->id,
            'name'          => $template->name,
            'total_seats'   => $template->total_seats,
            'vip_positions' => $template->vip_positions ?? [],
            'vip_seats'     => $template->vipSeatCount(),
            'is_active'     => $template->is_active,
        ];
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
