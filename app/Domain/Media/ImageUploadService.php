<?php

namespace App\Domain\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The one place that turns an `UploadedFile` into a stored image + public URL. Gift
 * thumbnails, gift-category icons and level badges all need exactly this and nothing
 * more — validation (size, mimetype) stays in each controller's own request rules,
 * since the acceptable size differs per use (docs/01 §6's image cap is the shared
 * ceiling, not a floor every caller must use).
 *
 * Writes to `config('filesystems.uploads_disk')` — local `public` until Vultr Object
 * Storage credentials exist, `vultr` once they do (config/filesystems.php). Nothing
 * here changes either way; only the env var does.
 */
class ImageUploadService
{
    public function store(UploadedFile $file, string $folder): array
    {
        $disk = config('filesystems.uploads_disk', 'public');

        // Explicit, every time — the same thing mehfil's own upload controllers do
        // (`Storage::disk('vultr')->put($path, $contents, 'public')`). The local disk
        // has 'visibility' => 'public' set at the disk level so this was invisible
        // there, but Vultr Object Storage denies reads on anything uploaded without an
        // explicit public-read ACL: a file lands, the URL is real, and it 403s anyway.
        $path = $file->storePublicly($folder, $disk);

        return [
            'url'  => Storage::disk($disk)->url($path),
            'path' => $path,
            'size' => $file->getSize(),
        ];
    }
}
