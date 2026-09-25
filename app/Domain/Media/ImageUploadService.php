<?php

namespace App\Domain\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
    /**
     * `$extension` overrides the one Laravel would guess from the file's content. Needed
     * for SVGA: it is a zip/zlib container, so the guess comes out as `.zip`/`.bin` and
     * the app's SVGA player then refuses the URL.
     */
    public function store(UploadedFile $file, string $folder, ?string $extension = null): array
    {
        $name = $extension === null ? $file->hashName() : Str::random(40).'.'.$extension;

        return $this->put($file, $folder, $name);
    }

    /**
     * Stores a file under the record it belongs to, the layout mehfil's bucket uses:
     * `{prefix}/{model}/{id}/{kind}_{unix time}.{ext}` — e.g.
     * `guftagu/storeItem/23/animation_1790316958.svga`. One folder per record keeps a
     * record's files findable (and deletable) together in the shared bucket.
     *
     * With no `$id` yet (a create that uploads first) the file sits directly under
     * `{prefix}/{model}/`, with a random suffix since there is no folder to keep it apart.
     */
    public function storeFor(UploadedFile $file, string $model, ?int $id, string $kind, ?string $extension = null): array
    {
        $extension ??= $file->guessExtension() ?? 'bin';
        $prefix = trim((string) config('filesystems.uploads_prefix'), '/');

        $folder = implode('/', array_filter([$prefix, $model, $id]));
        $name = $kind.'_'.time().($id === null ? '_'.Str::lower(Str::random(6)) : '').'.'.$extension;

        return $this->put($file, $folder, $name);
    }

    protected function put(UploadedFile $file, string $folder, string $name): array
    {
        $disk = config('filesystems.uploads_disk', 'public');

        // Explicit, every time — the same thing mehfil's own upload controllers do
        // (`Storage::disk('vultr')->put($path, $contents, 'public')`). The local disk
        // has 'visibility' => 'public' set at the disk level so this was invisible
        // there, but Vultr Object Storage denies reads on anything uploaded without an
        // explicit public-read ACL: a file lands, the URL is real, and it 403s anyway.
        $path = $file->storePubliclyAs($folder, $name, $disk);

        return [
            'url'  => Storage::disk($disk)->url($path),
            'path' => $path,
            'size' => $file->getSize(),
        ];
    }
}
