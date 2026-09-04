<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Media\ImageUploadService;
use App\Domain\Store\LevelException;
use App\Http\Controllers\Controller;
use App\Models\WealthCharmLevel;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GFT-027 / docs/00 §7 — the wealth/charm level ladder. Admin-configurable only; see
 * the `wealth_charm_levels` migration for why the mobile progression engine that reacts
 * to it (level-up notifications, rewards) is not built here.
 */
class LevelController extends Controller
{
    /** Same ceiling as every other admin-uploaded image (docs/01 §6). */
    public const MAX_BADGE_KB = 5120;

    public function __construct(
        protected AuditLogger $audit,
        protected ImageUploadService $uploads,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['sometimes', 'nullable', Rule::in(WealthCharmLevel::TYPES)],
        ]);

        $levels = WealthCharmLevel::query()
            ->when($data['type'] ?? null, fn ($q, string $t) => $q->ofType($t))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('type')->orderBy('level')
            ->get();

        return ApiResponse::success($levels->map(fn (WealthCharmLevel $l) => $this->payload($l)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateLevel($request);
        $this->assertMonotonic($data['type'], $data['level'], $data['threshold'], null);

        $level = WealthCharmLevel::create($data);

        $this->audit->log($request->user(), 'level.create', 'levels', WealthCharmLevel::class, $level->id, null, $data);

        return ApiResponse::success($this->payload($level), 'Level created', 201);
    }

    public function update(Request $request, WealthCharmLevel $level): JsonResponse
    {
        $data = $this->validateLevel($request, false, $level);

        $type = $data['type'] ?? $level->type;
        $lvl = $data['level'] ?? $level->level;
        $threshold = $data['threshold'] ?? $level->threshold;
        $this->assertMonotonic($type, $lvl, $threshold, $level->id);

        $before = $level->only(array_keys($data));
        $level->fill($data)->save();

        $this->audit->log($request->user(), 'level.update', 'levels', WealthCharmLevel::class, $level->id, $before, $data);

        return ApiResponse::success($this->payload($level->fresh()), 'Level updated');
    }

    /** Badge image upload — same pattern as the gift-thumbnail and category-icon uploads. */
    /**
     * `id` is optional: creating a level has none yet, so the panel just holds the
     * returned URL until Save. Editing one has an id, and passing it here saves the
     * badge immediately — one step instead of upload-then-remember-to-Save.
     */
    public function uploadBadge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => [
                'required', 'file', 'max:'.self::MAX_BADGE_KB,
                'mimes:jpg,jpeg,png,webp,gif',
            ],
            'id' => ['sometimes', 'nullable', 'integer', Rule::exists('wealth_charm_levels', 'id')],
        ], [
            'file.max' => 'That image is larger than '.(self::MAX_BADGE_KB / 1024).' MB.',
        ]);

        $result = $this->uploads->store($request->file('file'), 'level-badges');

        if (! empty($data['id'])) {
            $level = WealthCharmLevel::findOrFail($data['id']);
            $before = ['badge_url' => $level->badge_url];

            $level->forceFill(['badge_url' => $result['url']])->save();

            $this->audit->log($request->user(), 'level.update', 'levels', WealthCharmLevel::class, $level->id, $before, ['badge_url' => $result['url']]);

            $result['level'] = $this->payload($level->fresh());
        }

        return ApiResponse::success($result, 'Badge uploaded');
    }

    /**
     * A ladder where level 4 asks for fewer coins than level 3 cannot be resolved
     * sensibly by `WealthCharmLevel::resolveFor()` — it just picks the higher threshold
     * regardless of which level number that belongs to. Refuse rather than store a ladder
     * that would rank users out of order.
     */
    protected function assertMonotonic(string $type, int $level, int $threshold, ?int $ignoreId): void
    {
        $rows = WealthCharmLevel::query()
            ->ofType($type)
            ->when($ignoreId, fn ($q, int $id) => $q->where('id', '!=', $id))
            ->get(['level', 'threshold'])
            ->push((object) ['level' => $level, 'threshold' => $threshold])
            ->sortBy('level')
            ->values();

        for ($i = 1; $i < $rows->count(); $i++) {
            if ($rows[$i]->threshold <= $rows[$i - 1]->threshold) {
                throw new LevelException(
                    'LEVEL_THRESHOLD_ORDER_INVALID',
                    "Level {$rows[$i]->level}'s threshold must be higher than level {$rows[$i - 1]->level}'s — otherwise a higher level could need fewer coins than a lower one.",
                );
            }
        }
    }

    /** @return array<string, mixed> */
    protected function validateLevel(Request $request, bool $creating = true, ?WealthCharmLevel $level = null): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'type' => [
                $creating ? 'required' : 'sometimes',
                // Changing a level's type mid-ladder would silently orphan any wallet
                // override pointing at it — simplest to just not allow it.
                $level ? Rule::in([$level->type]) : Rule::in(WealthCharmLevel::TYPES),
            ],
            'level' => [
                $creating ? 'required' : 'sometimes',
                'integer', 'min:1', 'max:999',
                Rule::unique('wealth_charm_levels', 'level')
                    ->where('type', $request->input('type', $level?->type))
                    ->ignore($level?->id),
            ],
            'name_en'   => [$required, 'string', 'max:80'],
            'name_hi'   => ['sometimes', 'nullable', 'string', 'max:80'],
            'threshold' => [$required, 'integer', 'min:0', 'max:1000000000'],
            'badge_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    protected function payload(WealthCharmLevel $level): array
    {
        return [
            'id'         => $level->id,
            'type'       => $level->type,
            'level'      => $level->level,
            'name_en'    => $level->name_en,
            'name_hi'    => $level->name_hi,
            'threshold'  => $level->threshold,
            'badge_url'  => $level->badge_url,
            'is_active'  => $level->is_active,
        ];
    }
}
