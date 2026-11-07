<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Models\CmsPageVersion;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Settings → About Us / Privacy Policy / Terms of use: the published text of
 * a CMS page (A.10a). Public — terms and privacy must be readable before signing in.
 *
 * It serves the published *version* snapshot, never the page row itself: an admin's
 * unpublished edits live on the row until they publish, and a user must only ever see
 * text that was actually published.
 */
class PageController extends Controller
{
    /** GET /pages/{type} — `?lang=hi` for Hindi, falling back to English per field. */
    public function show(Request $request, string $type): JsonResponse
    {
        abort_unless(in_array($type, CmsPage::TYPES, true), 404);

        $page = CmsPage::query()
            ->where('type', $type)
            ->where('is_published', true)
            ->orderByDesc('published_at')
            ->first();

        $published = $page === null ? null : CmsPageVersion::query()
            ->where('cms_page_id', $page->id)
            ->where('version', $page->version)
            ->first();

        if ($published === null) {
            return ApiResponse::error('NOT_FOUND', 'This page has not been published yet.', null, 404);
        }

        $hindi = $request->query('lang') === 'hi';

        return ApiResponse::success([
            'type'         => $page->type,
            'title'        => ($hindi ? $published->title_hi : null) ?: $published->title_en,
            'content'      => ($hindi ? $published->content_hi : null) ?: $published->content_en,
            'version'      => $published->version,
            'published_at' => $page->published_at?->toIso8601ZuluString(),
        ]);
    }
}
