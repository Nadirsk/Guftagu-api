<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Services\PermissionResolver;
use App\Domain\Audit\AuditLogger;
use App\Domain\Cms\CmsService;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Banner;
use App\Models\CmsPage;
use App\Models\CmsPageVersion;
use App\Models\Faq;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * GFT-102 / GFT-103 / GFT-104 — banners, announcements, pages and FAQs (A.10a).
 *
 * Everything scheduled here reports `state` (`off` / `scheduled` / `live` / `expired`)
 * alongside `is_active`. The flag is the operator's intent; the state is what is true right
 * now, derived from the clock at read time.
 */
class ContentController extends Controller
{
    public function __construct(
        protected CmsService $cms,
        protected AuditLogger $audit,
        protected PermissionResolver $permissions,
    ) {
    }

    // ---------------------------------------------------------------- banners

    public function banners(Request $request): JsonResponse
    {
        $data = $request->validate([
            'placement' => ['sometimes', 'nullable', Rule::in(Banner::PLACEMENTS)],
            'state'     => ['sometimes', 'nullable', Rule::in(['live', 'scheduled', 'expired', 'off', 'awaiting_approval'])],
        ]);

        $banners = Banner::query()
            ->with(['creator:id,name', 'approver:id,name'])
            ->when($data['placement'] ?? null, fn ($q, string $p) => $q->where('placement', $p))
            ->orderBy('placement')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            // The state filter is applied after loading rather than in SQL: it is four
            // mutually exclusive windows, and expressing "expired" in the same query that
            // expresses "scheduled" makes both harder to read than they are worth here.
            // Banner counts are in the dozens, not the millions.
            ->when(
                filled($data['state'] ?? null),
                fn ($rows) => $rows->filter(fn (Banner $b) => $b->state() === $data['state'])->values(),
            );

        return ApiResponse::success([
            'banners'    => $banners->map(fn (Banner $b) => $this->bannerPayload($b)),
            'placements' => Banner::PLACEMENTS,
            // The queue an Admin needs to clear, counted rather than left to be noticed.
            'awaiting_approval' => Banner::query()->whereNull('approved_by')->count(),
            // A.10a asks for clicks counted per placement.
            'by_placement' => Banner::query()
                ->selectRaw('placement, COUNT(*) AS banners, SUM(click_count) AS clicks, SUM(impression_count) AS impressions')
                ->groupBy('placement')
                ->get()
                ->map(fn ($row) => [
                    'placement'   => $row->placement,
                    'banners'     => (int) $row->banners,
                    'clicks'      => (int) $row->clicks,
                    'impressions' => (int) $row->impressions,
                ]),
        ]);
    }

    public function storeBanner(Request $request): JsonResponse
    {
        $data = $this->bannerRules($request);

        // B.3b — whoever can approve has effectively approved by creating it; asking an
        // Admin to click their own banner live is ceremony. Anybody else gets a banner in
        // `awaiting_approval`, which does not show however active and in-window it is.
        $selfApproves = $this->permissions->has($request->user(), 'cms.banner_approve');

        $banner = Banner::create($data + [
            'created_by'  => $request->user()->id,
            'approved_by' => $selfApproves ? $request->user()->id : null,
        ]);

        $this->audit->log($request->user(), 'cms.banner_create', 'cms', Banner::class, $banner->id, null, $data);

        return ApiResponse::success(
            $this->bannerPayload($banner) + [
                'note' => $selfApproves
                    ? null
                    : 'Saved, but not showing: it needs an Admin with cms.banner_approve to sign it off.',
            ],
            $selfApproves ? 'Banner created' : 'Banner submitted for approval',
            201,
        );
    }

    /** POST /admin/content/banners/{banner}/approve — B.3b. */
    public function approveBanner(Request $request, Banner $banner): JsonResponse
    {
        if ($banner->isApproved()) {
            return ApiResponse::error('BAD_REQUEST', 'That banner is already approved.', null, 400);
        }

        $banner->forceFill(['approved_by' => $request->user()->id])->save();

        $this->audit->log($request->user(), 'cms.banner_approve', 'cms', Banner::class, $banner->id, [
            'approved_by' => null,
        ], ['approved_by' => $request->user()->id]);

        return ApiResponse::success([
            'state'   => $banner->fresh()->state(),
            'is_live' => $banner->fresh()->isLive(),
        ], 'Banner approved');
    }

    public function updateBanner(Request $request, Banner $banner): JsonResponse
    {
        $data = $this->bannerRules($request, $banner);
        $before = $banner->only(array_keys($data));

        $banner->fill($data);

        // An edit by somebody who cannot approve sends it back for approval. Otherwise a
        // Manager could get a harmless banner signed off and then swap the image and the
        // link afterwards — the approval would be of something that no longer exists.
        if ($banner->isApproved() && ! $this->permissions->has($request->user(), 'cms.banner_approve')) {
            $banner->approved_by = null;
        }

        $banner->save();

        $this->audit->log($request->user(), 'cms.banner_update', 'cms', Banner::class, $banner->id, $before, $data);

        return ApiResponse::success($this->bannerPayload($banner->refresh()), 'Banner updated');
    }

    public function destroyBanner(Request $request, Banner $banner): JsonResponse
    {
        $before = $banner->only(['title', 'placement', 'click_count']);

        $banner->delete();

        $this->audit->log($request->user(), 'cms.banner_delete', 'cms', Banner::class, $banner->id, $before, null);

        return ApiResponse::success(null, 'Banner removed');
    }

    // ---------------------------------------------------------- announcements

    public function announcements(Request $request): JsonResponse
    {
        $rows = Announcement::query()
            ->with('creator:id,name')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success([
            'announcements' => $rows->map(fn (Announcement $a) => [
                'id'         => $a->id,
                'title_en'   => $a->title_en,
                'title_hi'   => $a->title_hi,
                'body_en'    => $a->body_en,
                'body_hi'    => $a->body_hi,
                'type'       => $a->type,
                'target_roles' => $a->target_roles ?: [],
                'applies_to_everyone' => $a->appliesToEveryone(),
                'starts_at'  => $a->starts_at?->toIso8601ZuluString(),
                'ends_at'    => $a->ends_at?->toIso8601ZuluString(),
                'is_active'  => $a->is_active,
                'state'      => $a->state(),
                'created_by' => $a->creator?->name,
                // A Hindi version missing means the app falls back to English for
                // Hindi-speaking users, which is worth flagging before it ships.
                'bilingual'  => filled($a->title_hi) && filled($a->body_hi),
            ]),
            'types' => Announcement::TYPES,
        ]);
    }

    public function storeAnnouncement(Request $request): JsonResponse
    {
        $data = $this->announcementRules($request);

        $row = Announcement::create($data + ['created_by' => $request->user()->id]);

        $this->audit->log($request->user(), 'cms.announcement_create', 'cms', Announcement::class, $row->id, null, $data);

        return ApiResponse::success(['id' => $row->id, 'state' => $row->state()], 'Announcement created', 201);
    }

    public function updateAnnouncement(Request $request, Announcement $announcement): JsonResponse
    {
        $data = $this->announcementRules($request, $announcement);
        $before = $announcement->only(array_keys($data));

        $announcement->fill($data)->save();

        $this->audit->log($request->user(), 'cms.announcement_update', 'cms', Announcement::class, $announcement->id, $before, $data);

        return ApiResponse::success(['state' => $announcement->fresh()->state()], 'Announcement updated');
    }

    public function destroyAnnouncement(Request $request, Announcement $announcement): JsonResponse
    {
        $announcement->delete();

        $this->audit->log($request->user(), 'cms.announcement_delete', 'cms', Announcement::class, $announcement->id, null, null);

        return ApiResponse::success(null, 'Announcement removed');
    }

    // ------------------------------------------------------------------ pages

    public function pages(): JsonResponse
    {
        $pages = CmsPage::query()
            ->with('editor:id,name')
            ->withCount('versions')
            ->orderBy('type')
            ->orderBy('slug')
            ->get();

        return ApiResponse::success([
            'pages' => $pages->map(fn (CmsPage $p) => [
                'id'           => $p->id,
                'slug'         => $p->slug,
                'title_en'     => $p->title_en,
                'title_hi'     => $p->title_hi,
                'type'         => $p->type,
                'version'      => $p->version,
                'versions'     => $p->versions_count,
                'is_published' => $p->is_published,
                'is_legal'     => $p->isLegal(),
                'published_at' => $p->published_at?->toIso8601ZuluString(),
                'updated_by'   => $p->editor?->name,
                'bilingual'    => filled($p->title_hi) && filled($p->content_hi),
            ]),
            'types' => CmsPage::TYPES,
        ]);
    }

    public function showPage(CmsPage $page): JsonResponse
    {
        $page->load(['versions.creator:id,name', 'editor:id,name']);

        return ApiResponse::success([
            'page' => [
                'id'           => $page->id,
                'slug'         => $page->slug,
                'title_en'     => $page->title_en,
                'title_hi'     => $page->title_hi,
                'content_en'   => $page->content_en,
                'content_hi'   => $page->content_hi,
                'type'         => $page->type,
                'version'      => $page->version,
                'is_published' => $page->is_published,
                'is_legal'     => $page->isLegal(),
                'published_at' => $page->published_at?->toIso8601ZuluString(),
            ],
            'versions' => $page->versions->map(fn (CmsPageVersion $v) => [
                'id'         => $v->id,
                'version'    => $v->version,
                'title_en'   => $v->title_en,
                'created_by' => $v->creator?->name,
                'created_at' => $v->created_at?->toIso8601ZuluString(),
                // Enough to see what changed without shipping the whole document twice.
                'length'     => mb_strlen($v->content_en),
            ]),
            'note' => $page->isLegal()
                ? 'Every published version of a terms or privacy page is kept — it is the only record of what a user agreed to on a given date.'
                : null,
        ]);
    }

    public function storePage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug'       => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('cms_pages', 'slug')],
            'title_en'   => ['required', 'string', 'max:200'],
            'title_hi'   => ['sometimes', 'nullable', 'string', 'max:200'],
            'content_en' => ['required', 'string'],
            'content_hi' => ['sometimes', 'nullable', 'string'],
            'type'       => ['required', Rule::in(CmsPage::TYPES)],
        ]);

        $page = CmsPage::create($data + ['version' => 0, 'is_published' => false, 'updated_by' => $request->user()->id]);

        $this->audit->log($request->user(), 'cms.page_create', 'cms', CmsPage::class, $page->id, null, ['slug' => $page->slug]);

        return ApiResponse::success(['id' => $page->id, 'slug' => $page->slug], 'Page created as a draft', 201);
    }

    public function updatePage(Request $request, CmsPage $page): JsonResponse
    {
        $data = $request->validate([
            'title_en'   => ['sometimes', 'string', 'max:200'],
            'title_hi'   => ['sometimes', 'nullable', 'string', 'max:200'],
            'content_en' => ['sometimes', 'string'],
            'content_hi' => ['sometimes', 'nullable', 'string'],
            'type'       => ['sometimes', Rule::in(CmsPage::TYPES)],
        ]);

        $this->cms->save($page, $data, $request->user());

        return ApiResponse::success([
            'version' => $page->fresh()->version,
            'note'    => 'Saved as a draft edit. Publish to cut a new version.',
        ], 'Page saved');
    }

    public function publishPage(Request $request, CmsPage $page): JsonResponse
    {
        $published = $this->cms->publish($page, $request->user());

        return ApiResponse::success([
            'version'      => $published->version,
            'published_at' => $published->published_at?->toIso8601ZuluString(),
        ], "Published as version {$published->version}");
    }

    public function unpublishPage(Request $request, CmsPage $page): JsonResponse
    {
        $this->cms->unpublish($page, $request->user());

        return ApiResponse::success(null, 'Page unpublished');
    }

    public function restorePage(Request $request, CmsPage $page, CmsPageVersion $version): JsonResponse
    {
        $restored = $this->cms->restore($page, $version, $request->user());

        return ApiResponse::success([
            'version' => $restored->version,
            'note'    => "Version {$version->version} is live again, published as version {$restored->version}. Nothing was deleted.",
        ], 'Version restored');
    }

    // ------------------------------------------------------------------- FAQs

    public function faqs(Request $request): JsonResponse
    {
        $rows = Faq::query()
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success([
            'faqs' => $rows->map(fn (Faq $f) => [
                'id'          => $f->id,
                'category'    => $f->category,
                'question_en' => $f->question_en,
                'question_hi' => $f->question_hi,
                'answer_en'   => $f->answer_en,
                'answer_hi'   => $f->answer_hi,
                'sort_order'  => $f->sort_order,
                'is_active'   => $f->is_active,
                'bilingual'   => $f->isBilingual(),
            ]),
            'categories' => Faq::query()->distinct()->orderBy('category')->pluck('category'),
            // Worth surfacing rather than leaving somebody to notice in the app.
            'missing_hindi' => Faq::query()->active()
                ->where(fn ($q) => $q->whereNull('answer_hi')->orWhere('answer_hi', ''))
                ->count(),
        ]);
    }

    public function storeFaq(Request $request): JsonResponse
    {
        $data = $this->faqRules($request);

        $faq = Faq::create($data);

        $this->audit->log($request->user(), 'cms.faq_create', 'cms', Faq::class, $faq->id, null, $data);

        return ApiResponse::success(['id' => $faq->id], 'FAQ created', 201);
    }

    public function updateFaq(Request $request, Faq $faq): JsonResponse
    {
        $data = $this->faqRules($request, $faq);
        $before = $faq->only(array_keys($data));

        $faq->fill($data)->save();

        $this->audit->log($request->user(), 'cms.faq_update', 'cms', Faq::class, $faq->id, $before, $data);

        return ApiResponse::success(null, 'FAQ updated');
    }

    public function destroyFaq(Request $request, Faq $faq): JsonResponse
    {
        $faq->delete();

        $this->audit->log($request->user(), 'cms.faq_delete', 'cms', Faq::class, $faq->id, null, null);

        return ApiResponse::success(null, 'FAQ removed');
    }

    public function reorderFaqs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order'   => ['required', 'array', 'min:1'],
            'order.*' => ['integer', Rule::exists('faqs', 'id')],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['order'] as $position => $id) {
                Faq::whereKey($id)->update(['sort_order' => ($position + 1) * 10]);
            }
        });

        $this->audit->log($request->user(), 'cms.faq_reorder', 'cms', Faq::class, null, null, ['count' => count($data['order'])]);

        return ApiResponse::success(null, 'Order saved');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    protected function bannerRules(Request $request, ?Banner $banner = null): array
    {
        $required = $banner === null ? 'required' : 'sometimes';

        return $request->validate([
            'title'        => [$required, 'string', 'max:160'],
            'image_url'    => [$required, 'url', 'max:500'],
            'placement'    => [$required, Rule::in(Banner::PLACEMENTS)],
            'action_type'  => ['sometimes', 'nullable', Rule::in(Banner::ACTION_TYPES)],
            'action_value' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sort_order'   => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'starts_at'    => ['sometimes', 'nullable', 'date'],
            // A window that closes before it opens would never show, and nothing else
            // would ever say why.
            'ends_at'      => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'is_active'    => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function announcementRules(Request $request, ?Announcement $announcement = null): array
    {
        $required = $announcement === null ? 'required' : 'sometimes';

        return $request->validate([
            'title_en'     => [$required, 'string', 'max:200'],
            'title_hi'     => ['sometimes', 'nullable', 'string', 'max:200'],
            'body_en'      => [$required, 'string', 'max:5000'],
            'body_hi'      => ['sometimes', 'nullable', 'string', 'max:5000'],
            'type'         => ['sometimes', Rule::in(Announcement::TYPES)],
            'target_roles' => ['sometimes', 'nullable', 'array'],
            'target_roles.*' => ['string', 'max:40'],
            'starts_at'    => ['sometimes', 'nullable', 'date'],
            'ends_at'      => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'is_active'    => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function faqRules(Request $request, ?Faq $faq = null): array
    {
        $required = $faq === null ? 'required' : 'sometimes';

        return $request->validate([
            'category'    => ['sometimes', 'string', 'max:60'],
            'question_en' => [$required, 'string', 'max:300'],
            'question_hi' => ['sometimes', 'nullable', 'string', 'max:300'],
            'answer_en'   => [$required, 'string', 'max:5000'],
            'answer_hi'   => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sort_order'  => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);
    }

    protected function bannerPayload(Banner $banner): array
    {
        return [
            'id'           => $banner->id,
            'title'        => $banner->title,
            'image_url'    => $banner->image_url,
            'placement'    => $banner->placement,
            'action_type'  => $banner->action_type,
            'action_value' => $banner->action_value,
            'sort_order'   => $banner->sort_order,
            'starts_at'    => $banner->starts_at?->toIso8601ZuluString(),
            'ends_at'      => $banner->ends_at?->toIso8601ZuluString(),
            'is_active'    => $banner->is_active,
            // What the operator set, versus what is actually showing right now.
            'state'        => $banner->state(),
            'is_live'      => $banner->isLive(),
            'is_approved'  => $banner->isApproved(),
            'approved_by'  => $banner->approver?->name,
            'click_count'  => $banner->click_count,
            'impression_count' => $banner->impression_count,
            'click_rate'   => $banner->clickRate(),
            'created_by'   => $banner->creator?->name,
        ];
    }
}
