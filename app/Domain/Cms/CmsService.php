<?php

namespace App\Domain\Cms;

use App\Domain\Audit\AuditLogger;
use App\Models\AdminUser;
use App\Models\CmsPage;
use App\Models\CmsPageVersion;
use Illuminate\Support\Facades\DB;

/**
 * GFT-104 — CMS pages with versioning (A.10a).
 *
 * The rule that matters: **publishing cuts a version, it never overwrites one.** Terms and
 * privacy pages are what a user consented to on a given date, so the text as it stood then
 * has to remain retrievable. A draft edit is free; a publish is a historical fact.
 */
class CmsService
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    /**
     * Save an edit. Unpublished pages are edited in place; publishing snapshots.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws CmsException
     */
    public function save(CmsPage $page, array $data, AdminUser $actor): CmsPage
    {
        $before = $page->only(['title_en', 'content_en', 'version', 'is_published']);

        $page->fill($data);
        $page->updated_by = $actor->id;
        $page->save();

        $this->audit->log($actor, 'cms.page_save', 'cms', CmsPage::class, $page->id, $before, [
            'version' => $page->version, 'is_published' => $page->is_published,
        ]);

        return $page->refresh();
    }

    /**
     * Publish the current text as a new version.
     *
     * Refuses to cut a version identical to the last one — a version history full of
     * no-op entries makes the real change impossible to find, and for a legal page that is
     * the whole point of keeping it.
     *
     * @throws CmsException
     */
    public function publish(CmsPage $page, AdminUser $actor): CmsPage
    {
        $latest = $page->versions()->first();

        if ($latest !== null && $this->sameContent($page, $latest)) {
            throw new CmsException(
                'NO_CHANGES',
                "Version {$latest->version} already says exactly this. Nothing to publish.",
                400,
            );
        }

        return DB::transaction(function () use ($page, $actor) {
            $version = ($page->versions()->max('version') ?? 0) + 1;

            CmsPageVersion::create([
                'cms_page_id' => $page->id,
                'version'     => $version,
                'title_en'    => $page->title_en,
                'title_hi'    => $page->title_hi,
                'content_en'  => $page->content_en,
                'content_hi'  => $page->content_hi,
                'created_by'  => $actor->id,
            ]);

            $page->forceFill([
                'version'      => $version,
                'is_published' => true,
                'published_at' => now(),
                'updated_by'   => $actor->id,
            ])->save();

            $this->audit->log($actor, 'cms.page_publish', 'cms', CmsPage::class, $page->id, null, [
                'version' => $version, 'slug' => $page->slug,
            ]);

            return $page->refresh();
        });
    }

    /**
     * Roll the live text back to an earlier version.
     *
     * The rollback is itself published as a **new** version rather than deleting the ones
     * after it. Removing history to undo a mistake is how you end up unable to prove what
     * a user agreed to.
     *
     * @throws CmsException
     */
    public function restore(CmsPage $page, CmsPageVersion $version, AdminUser $actor): CmsPage
    {
        if ($version->cms_page_id !== $page->id) {
            throw new CmsException('VALIDATION_ERROR', 'That version belongs to a different page.', 422);
        }

        $page->fill([
            'title_en'   => $version->title_en,
            'title_hi'   => $version->title_hi,
            'content_en' => $version->content_en,
            'content_hi' => $version->content_hi,
        ])->save();

        $restored = $this->publish($page, $actor);

        $this->audit->log($actor, 'cms.page_restore', 'cms', CmsPage::class, $page->id, null, [
            'restored_from' => $version->version, 'new_version' => $restored->version,
        ]);

        return $restored;
    }

    /**
     * Unpublish — the page stops being served, its versions stay.
     *
     * @throws CmsException
     */
    public function unpublish(CmsPage $page, AdminUser $actor): CmsPage
    {
        if ($page->isLegal()) {
            throw new CmsException(
                'LEGAL_PAGE',
                "Terms and privacy pages cannot be unpublished — the app has to show something. Publish a replacement instead.",
                422,
            );
        }

        $page->forceFill(['is_published' => false, 'updated_by' => $actor->id])->save();

        $this->audit->log($actor, 'cms.page_unpublish', 'cms', CmsPage::class, $page->id, null, null);

        return $page->refresh();
    }

    protected function sameContent(CmsPage $page, CmsPageVersion $version): bool
    {
        return $page->title_en === $version->title_en
            && $page->title_hi === $version->title_hi
            && $page->content_en === $version->content_en
            && $page->content_hi === $version->content_hi;
    }
}
