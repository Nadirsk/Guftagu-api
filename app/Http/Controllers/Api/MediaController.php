<?php

namespace App\Http\Controllers\Api;

use App\Domain\Media\ImageUploadService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile media uploads — the missing half of `POST /posts`.
 *
 * `posts.media_urls` stores URLs, not files, and every other `upload*` route in
 * this app is admin-panel only. The app had no way to turn a picked photo or
 * clip into a URL, so composing a moment with media was impossible. This is
 * that one step: a file in, a public URL out, which the client then passes to
 * `POST /posts`.
 *
 * Storage goes through {@see ImageUploadService}, which already handles the
 * local/Vultr disk switch and the explicit public ACL. Validation lives here,
 * because the acceptable size differs per use.
 */
class MediaController extends Controller
{
    /**
     * 100 MB — one cap for both kinds, and what the compose screen promises
     * (node 99:1443 says 10, which is about fifteen seconds of phone video, so
     * the number on that label is the thing that gives).
     *
     * Well inside php.ini's own `upload_max_filesize`/`post_max_size`; raising
     * this past either of those would fail as an empty request, not a 422.
     */
    public const MAX_KB = 102400;

    public const IMAGE_MIMES = 'jpg,jpeg,png,webp,gif';

    public const VIDEO_MIMES = 'mp4,mov,m4v,webm,3gp';

    /** Voice notes: what a phone's recorder produces (AAC in an m4a/mp4/3gp container) and the common others. */
    public const AUDIO_MIMES = 'm4a,aac,mp3,wav,ogg,oga,opus,amr';

    public function __construct(protected ImageUploadService $uploads)
    {
    }

    /** POST /media */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required', 'file', 'max:'.self::MAX_KB,
                'mimes:'.self::IMAGE_MIMES.','.self::VIDEO_MIMES.','.self::AUDIO_MIMES,
            ],
        ], [
            'file.max'   => 'That file is larger than '.(self::MAX_KB / 1024).' MB.',
            'file.mimes' => 'Only images, videos and voice notes can be uploaded.',
        ]);

        $file = $request->file('file');
        $result = $this->uploads->store($file, 'moment-media');

        // The client needs to know which kind came back so it can pick the
        // post's `type` without re-inspecting the URL. Read from the detected
        // media type rather than the filename: a streamed upload sends whatever
        // the picker's cache file happened to be called, which on some devices
        // carries no extension at all.
        $mime = (string) $file->getMimeType();
        $result['kind'] = str_starts_with($mime, 'video/')
            ? 'video'
            : (str_starts_with($mime, 'audio/') ? 'audio' : 'image');

        return ApiResponse::success($result, 'Uploaded');
    }
}
