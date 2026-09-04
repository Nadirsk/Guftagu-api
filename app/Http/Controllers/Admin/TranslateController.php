<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A draft-translation helper for the bilingual name fields scattered across the catalogue
 * screens (room/gift categories, gifts, levels, VIP tiers) — not the app's own i18n system
 * (that is `translations` + `vue-i18n`, epic E.5). This just saves an admin from typing the
 * Hindi name by hand; the field stays editable, so a wrong or awkward machine translation is
 * always one correction away rather than a blocker.
 *
 * Backed by MyMemory's public translation API (mymemory.translated.net) — free, documented,
 * no key required for this volume. It was chosen over Google Translate's unauthenticated
 * `translate_a/single` endpoint, which is undocumented and started returning HTTP 429
 * "automated queries" blocks after only a couple of requests during testing. MyMemory has an
 * explicit daily anonymous quota (~5,000 words), reported via `quotaFinished` rather than a
 * silent block, and a `de` (contact email) query param raises that quota if it is ever hit —
 * not configured here since usage is a handful of lookups per admin session.
 */
class TranslateController extends Controller
{
    public function translate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text'   => ['required', 'string', 'max:200'],
            'target' => ['sometimes', 'string', 'in:hi'],
        ]);

        $text = trim($data['text']);
        if ($text === '') {
            return ApiResponse::success(['translated' => '']);
        }

        try {
            $response = Http::timeout(5)->get('https://api.mymemory.translated.net/get', [
                'q'        => $text,
                'langpair' => 'en|'.($data['target'] ?? 'hi'),
            ]);

            // A quota-exhausted or malformed response is treated as "no translation
            // available" rather than surfaced as a panel error — the field is optional and
            // the admin can always type it themselves.
            if (! $response->successful() || $response->json('quotaFinished') === true) {
                return ApiResponse::success(['translated' => null]);
            }

            $translated = $response->json('responseData.translatedText');

            return ApiResponse::success(['translated' => is_string($translated) && $translated !== '' ? $translated : null]);
        } catch (\Throwable $e) {
            Log::warning('translate.lookup_failed', ['message' => $e->getMessage()]);

            return ApiResponse::success(['translated' => null]);
        }
    }
}
