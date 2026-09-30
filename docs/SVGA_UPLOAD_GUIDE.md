# SVGA / animation upload for store items: implementation guide

This guide covers everything done in Guftagu (Laravel 12 + Vue 3 / Element Plus admin, Vultr Object
Storage) so you can repeat it in another project. It builds three things:

1. **Upload** SVGA / Lottie (.json) / MP4 from the admin panel, next to the "Animation URL" field.
2. **Clean bucket URLs** in the mehfil style:
   `https://blr1.vultrobjects.com/space-1/<prefix>/storeItem/<id>/animation_<unix time>.svga`
   (and `photo_<unix time>.png` for images).
3. **Preview**: SVGA plays in the edit dialog, on the store cards, and on a standalone
   `/preview?src=<url>` page that works in Chrome.

Rename `StoreItem`, `storeItem`, `store_items`, `/admin/store-items` and `guftagu` to match the
target project.

---

## 0. Things that are easy to get wrong (read first)

| Problem | Cause | Fix used |
|---|---|---|
| Valid `.svga` files get rejected on upload | SVGA is a zlib/zip container, so its sniffed mimetype is `application/zlib`, `application/zip` or `octet-stream`. A `mimes:` or `mimetypes:` rule rejects it. | Validate with Laravel's **`extensions:svga,json,mp4`** rule (checks the client extension). |
| File is stored as `.zip` / `.bin` | `storePublicly()` → `hashName()` guesses the extension from the content. | Store with `storePubliclyAs()` and the **original extension**. |
| URL opens as `AccessDenied` on Vultr | Either the object was uploaded without a `public` ACL, or the URL is wrong. Vultr returns `AccessDenied` for a **missing key** too (e.g. a typo in the folder name). | Always upload with `public` visibility, and check the exact key before debugging ACLs. |
| URL looks like `https://space-1.blr1.vultrobjects.com/...` | Virtual-host style is the S3 default. | `VULTR_USE_PATH_STYLE_ENDPOINT=true` gives `https://blr1.vultrobjects.com/space-1/...`. Both styles work; path style matches mehfil. |
| Opening the `.svga` URL in Chrome only downloads it | Browsers have **no native SVGA support**. No header fixes this. | A preview page that plays it with `svgaplayerweb`. |
| Upload shows `(canceled)` in the Network tab | axios `timeout: 20_000`. A 20 MB SVGA took ~16 s browser → API → Vultr, so anything bigger gets aborted. | Pass `{ timeout: 300_000 }` on the animation upload call. Add `set_time_limit(300)` in the action, because on Windows PHP's `max_execution_time` counts network wait too. |
| Error just says "Validation failed" | The 422's real reason is in `error.details.file`, not `message`. | Show `e.fieldErrors.file ?? e.message`. Also check the size on pick in the browser. |
| Create "succeeds" but the animation is missing | The create call worked, the follow-up upload failed, and the dialog still closed. | After create set `form.id = data.id`. If an `uploadNow` returns `null`, keep the dialog open and warn. |
| Player fails to load a remote SVGA | `svgaplayerweb` fetches with XHR, so it needs CORS. | Vultr bucket already answers GET with `Access-Control-Allow-Origin: *`. Check with `curl -s -D - -o /dev/null -H "Origin: http://localhost:5173" <url>`. |

---

## 1. Backend (Laravel)

### 1.1 `.env`

```env
UPLOADS_DISK=vultr
UPLOADS_PREFIX=guftagu            # top-level folder in the shared bucket (mehfil uses "mehfil")
VULTR_USE_PATH_STYLE_ENDPOINT=true
VULTR_ENDPOINT=https://blr1.vultrobjects.com
VULTR_BUCKET=space-1
VULTR_DEFAULT_REGION=blr1
VULTR_ACCESS_KEY_ID=...
VULTR_SECRET_ACCESS_KEY=...
```

Add `UPLOADS_PREFIX=guftagu` to `.env.example` too. Run `php artisan config:clear` afterwards.

### 1.2 `config/filesystems.php`

```php
'uploads_disk' => env('UPLOADS_DISK', 'public'),

// Top-level folder for this app's uploads. The Vultr bucket is shared with mehfil
// (which writes under `mehfil/`), so this app's files go under their own prefix.
'uploads_prefix' => env('UPLOADS_PREFIX', 'guftagu'),

'disks' => [
    // ...
    'vultr' => [
        'driver' => 's3',
        'key' => env('VULTR_ACCESS_KEY_ID'),
        'secret' => env('VULTR_SECRET_ACCESS_KEY'),
        'region' => env('VULTR_DEFAULT_REGION'),
        'bucket' => env('VULTR_BUCKET'),
        'endpoint' => env('VULTR_ENDPOINT'),
        'url' => env('VULTR_URL'),
        'use_path_style_endpoint' => env('VULTR_USE_PATH_STYLE_ENDPOINT', true),
        'visibility' => 'public',
        'throw' => false,
        'report' => false,
    ],
],
```

(Needs `league/flysystem-aws-s3-v3`.)

### 1.3 `app/Domain/Media/ImageUploadService.php`

```php
<?php

namespace App\Domain\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageUploadService
{
    /**
     * Old flat layout (`{folder}/{random}.{ext}`), kept for other callers.
     * `$extension` overrides the guessed one. Needed for SVGA, whose content guesses as .zip/.bin.
     */
    public function store(UploadedFile $file, string $folder, ?string $extension = null): array
    {
        $name = $extension === null ? $file->hashName() : Str::random(40).'.'.$extension;

        return $this->put($file, $folder, $name);
    }

    /**
     * mehfil-style layout: `{prefix}/{model}/{id}/{kind}_{unix time}.{ext}`
     * e.g. `guftagu/storeItem/23/animation_1790316958.svga`.
     * With no `$id` yet the file goes under `{prefix}/{model}/` with a random suffix.
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

        // Public ACL explicitly on every write. Vultr denies reads on objects uploaded without it.
        $path = $file->storePubliclyAs($folder, $name, $disk);

        return [
            'url'  => Storage::disk($disk)->url($path),
            'path' => $path,
            'size' => $file->getSize(),
        ];
    }
}
```

### 1.4 Controller: `app/Http/Controllers/Admin/StoreItemController.php`

Constants:

```php
public const MAX_IMAGE_KB = 5120;
// Entrance effects are full-screen SVGAs and routinely pass 10 MB.
public const MAX_ANIMATION_KB = 51200;   // 50 MB

/** File extension → the `animation_type` value an entrance effect stores. */
public const ANIMATION_EXTENSIONS = ['svga' => 'svga', 'json' => 'lottie', 'mp4' => 'mp4'];
```

Image upload now uses the record-scoped layout:

```php
$result = $this->uploads->storeFor($request->file('file'), 'storeItem', $data['id'] ?? null, 'photo');
```

New animation upload action:

```php
/**
 * SVGA, Lottie JSON or MP4. Validated by extension, not mimetype (SVGA sniffs as zip/zlib).
 * With `id`, the URL (and `animation_type` for an entrance effect) is saved onto the item now.
 */
public function uploadAnimation(Request $request): JsonResponse
{
    $data = $request->validate([
        'file' => [
            'required', 'file', 'max:'.self::MAX_ANIMATION_KB,
            'extensions:'.implode(',', array_keys(self::ANIMATION_EXTENSIONS)),
        ],
        'id' => ['sometimes', 'nullable', 'integer', Rule::exists('store_items', 'id')],
    ], [
        'file.max'        => 'That file is larger than '.(self::MAX_ANIMATION_KB / 1024).' MB. Compress it or shorten the animation.',
        'file.extensions' => 'Upload an SVGA, Lottie (.json) or MP4 file.',
    ]);

    // A 50 MB push to Vultr can outlast max_execution_time (wall-clock on Windows).
    set_time_limit(300);

    $file = $request->file('file');
    $extension = strtolower($file->getClientOriginalExtension());
    $type = self::ANIMATION_EXTENSIONS[$extension];

    $result = $this->uploads->storeFor($file, 'storeItem', $data['id'] ?? null, 'animation', $extension);

    if (! empty($data['id'])) {
        $item = StoreItem::findOrFail($data['id']);

        $changes = ['animation_url' => $result['url']];
        if ($item->type === 'entrance_effect') {
            $changes['animation_type'] = $type;
        }

        $before = $item->only(array_keys($changes));
        $item->forceFill($changes)->save();

        $this->audit->log($request->user(), 'store_item.update', 'vip', StoreItem::class, $item->id, $before, $changes);
    }

    return ApiResponse::success([...$result, 'type' => $type], 'Animation uploaded');
}
```

The `extensions:` rule exists in Laravel 11+. On Laravel 10 or older, use a closure rule that checks
`strtolower($file->getClientOriginalExtension())`.

Response `data`: `{ url, path, size, type }`, where `type` is `svga | lottie | mp4`.

### 1.5 Route: `routes/api.php`

Put it inside the same admin + permission group as the image upload:

```php
Route::post('store-items/image', [StoreItemController::class, 'uploadImage'])->name('store-items.image');
Route::post('store-items/animation', [StoreItemController::class, 'uploadAnimation'])->name('store-items.animation');
```

PHP limits: `upload_max_filesize` and `post_max_size` in `php.ini` must be above 50 MB.

### 1.6 Tests: `tests/Feature/Admin/StoreManagementTest.php`

```php
#[Test]
public function a_store_item_svga_upload_keeps_its_extension_and_saves_onto_the_item(): void
{
    Storage::fake('public');

    $effect = StoreItem::create(['type' => 'entrance_effect', 'name' => 'Dragon', 'coin_price' => 0]);

    $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
        ->postJson($this->base.'/store-items/animation', [
            'file' => UploadedFile::fake()->create('dragon.svga', 800, 'application/zip'),
            'id'   => $effect->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.type', 'svga');

    $this->assertMatchesRegularExpression(
        '#^guftagu/storeItem/'.$effect->id.'/animation_\d+\.svga$#',
        $response->json('data.path'),
    );
    Storage::disk('public')->assertExists($response->json('data.path'));

    $effect->refresh();
    $this->assertSame($response->json('data.url'), $effect->animation_url);
    $this->assertSame('svga', $effect->animation_type);
}

#[Test]
public function a_store_item_animation_without_an_id_only_returns_the_url(): void
{
    Storage::fake('public');

    $this->actingAs($this->superAdmin, 'sanctum-admin')
        ->postJson($this->base.'/store-items/animation', [
            'file' => UploadedFile::fake()->create('ring.svga', 200),
        ])
        ->assertOk()
        ->assertJsonStructure(['data' => ['url', 'path', 'size', 'type']]);
}

#[Test]
public function a_store_item_animation_rejects_other_file_types_and_oversized_files(): void
{
    Storage::fake('public');

    $this->actingAs($this->superAdmin, 'sanctum-admin')
        ->postJson($this->base.'/store-items/animation', ['file' => UploadedFile::fake()->create('frame.exe', 10)])
        ->assertStatus(422);

    $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
        ->postJson($this->base.'/store-items/animation', ['file' => UploadedFile::fake()->create('huge.svga', 60 * 1024)])
        ->assertStatus(422);

    $this->assertStringContainsString('larger than', $response->json('error.details.file.0'));
}
```

The test env must use `UPLOADS_DISK=public` (and `UPLOADS_PREFIX=guftagu`, or the default) so that
`Storage::fake('public')` catches the writes.

---

## 2. Admin panel (Vue 3 + Element Plus + TypeScript)

### 2.1 Install the SVGA web player

```bash
npm install svgaplayerweb@2.3.2
```

It plays SVGA 1.x and 2.x. A 2.x file starts with bytes `78 9c` (zlib). The package ships its own
types, and `import { Parser, Player } from 'svgaplayerweb'` works under Vite.

### 2.2 Types: `src/types/api.ts`

```ts
export type AnimationType = 'lottie' | 'svga' | 'mp4'

export interface ImageUploadResult {
  url: string
  path: string
  size: number
}

export interface AnimationUploadResult extends ImageUploadResult {
  type: AnimationType
}
```

### 2.3 `src/components/AnimationPreview.vue` (the player)

```vue
<script setup lang="ts">
import { Parser, Player } from 'svgaplayerweb'
import { computed, onBeforeUnmount, ref, watch } from 'vue'

/**
 * Plays SVGA (via svgaplayerweb) or MP4 (<video>). No Lottie preview.
 * `src` may be a remote URL (needs CORS) or a local `blob:` URL of a picked file.
 */
const props = withDefaults(
  defineProps<{
    src: string
    /** Overrides detection from the URL. Needed for `blob:` URLs, which have no extension. */
    type?: string | null
    size?: number
    /** No box of its own, for dropping into a slot that already has one (store cards). */
    bare?: boolean
  }>(),
  { type: null, size: 160, bare: false },
)

const kind = computed(() => {
  if (props.type) return props.type
  const ext = props.src.split('?')[0].split('.').pop()?.toLowerCase()
  return ext === 'json' ? 'lottie' : ext
})

const host = ref<HTMLDivElement | null>(null)
const failed = ref(false)
const loading = ref(false)
let player: Player | null = null

function teardown() {
  player?.stopAnimation(true)
  player?.clear()
  player = null
  if (host.value) host.value.innerHTML = ''
}

function playSvga() {
  teardown()
  failed.value = false
  if (!host.value || !props.src) return

  loading.value = true
  player = new Player(host.value)
  player.loops = 0
  player.setContentMode('AspectFit')

  const requested = props.src
  new Parser().load(
    requested,
    (video) => {
      // A newer src may have arrived while this one was downloading.
      if (requested !== props.src || !player) return
      loading.value = false
      player.setVideoItem(video)
      player.startAnimation()
    },
    () => {
      if (requested !== props.src) return
      loading.value = false
      failed.value = true
    },
  )
}

watch(
  [() => props.src, kind, host],
  () => {
    if (kind.value === 'svga') playSvga()
    else teardown()
  },
  { immediate: true, flush: 'post' },
)

onBeforeUnmount(teardown)
</script>

<template>
  <div
    class="relative flex items-center justify-center overflow-hidden"
    :class="{ 'border border-[var(--color-edge)] bg-[var(--color-recess)]': !bare }"
    :style="{ width: `${size}px`, height: `${size}px`, borderRadius: bare ? undefined : '4px' }"
  >
    <template v-if="kind === 'svga'">
      <div ref="host" class="h-full w-full" />
      <span v-if="loading" class="eyebrow absolute">loading…</span>
      <span v-if="failed" class="eyebrow absolute px-2 text-center text-[var(--color-cut)]">
        could not play this SVGA
      </span>
    </template>
    <video
      v-else-if="kind === 'mp4'"
      :src="src"
      class="h-full w-full object-contain"
      autoplay
      loop
      muted
      playsinline
    />
    <span v-else class="eyebrow px-2 text-center">no preview for {{ kind || 'this file' }}</span>
  </div>
</template>
```

The CSS classes (`eyebrow`, `--color-*` tokens) come from this project's theme. Swap them for the
target project's own.

### 2.4 `src/components/AnimationUpload.vue` (URL input + Upload button + preview)

Behaviour matches the image uploader:
- **Editing** (`recordId` set): the file uploads as soon as it is picked, and the backend saves it onto the item.
- **Creating** (`recordId` null): the file is held and previewed locally. The parent calls
  `uploadNow(newId)` right after the create call returns the new id, so the file lands in `storeItem/<id>/`.

```vue
<script setup lang="ts">
import { ElMessage } from 'element-plus'
import { computed, onBeforeUnmount, ref } from 'vue'

import AnimationPreview from '@/components/AnimationPreview.vue'
import { ApiError, api } from '@/lib/api'
import type { AnimationType, AnimationUploadResult } from '@/types/api'

const props = withDefaults(
  defineProps<{
    modelValue: string
    /** e.g. `/admin/store-items/animation` */
    uploadUrl: string
    recordId?: number | null
    label?: string
  }>(),
  { label: 'Animation', recordId: null },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
  /** The file's animation type, so the parent can fill its own type field. */
  type: [type: AnimationType]
  saved: []
}>()

const EXTENSION_TYPES: Record<string, AnimationType> = { svga: 'svga', json: 'lottie', mp4: 'mp4' }

/** Mirrors the backend MAX_ANIMATION_KB, so an oversized file is refused on pick, not after Save. */
const MAX_MB = 50

const input = ref<HTMLInputElement | null>(null)
const uploading = ref(false)

const pendingFile = ref<File | null>(null)
const pendingUrl = ref<string | null>(null)
const pendingType = ref<AnimationType | null>(null)
const hasPendingFile = computed(() => pendingFile.value !== null)

const previewSrc = computed(() => pendingUrl.value ?? props.modelValue)
/** Opens the standalone player page. A raw `.svga` URL only downloads in a browser. */
const previewPage = computed(() =>
  props.modelValue ? `/preview?src=${encodeURIComponent(props.modelValue)}` : null,
)

function revokePending() {
  if (pendingUrl.value) URL.revokeObjectURL(pendingUrl.value)
  pendingUrl.value = null
  pendingFile.value = null
  pendingType.value = null
}

async function onFileChange(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (input.value) input.value.value = ''
  if (!file) return

  const type = EXTENSION_TYPES[file.name.split('.').pop()?.toLowerCase() ?? '']
  if (!type) {
    ElMessage.error('Upload an SVGA, Lottie (.json) or MP4 file.')
    return
  }
  if (file.size > MAX_MB * 1024 * 1024) {
    ElMessage.error(`That file is ${(file.size / 1024 / 1024).toFixed(1)} MB. The limit is ${MAX_MB} MB.`)
    return
  }
  emit('type', type)

  if (props.recordId !== null) {
    await doUpload(file, props.recordId)
    return
  }

  revokePending()
  pendingFile.value = file
  pendingUrl.value = URL.createObjectURL(file)
  pendingType.value = type
}

async function doUpload(file: File, recordId: number): Promise<string | null> {
  uploading.value = true
  const body = new FormData()
  body.append('file', file)
  body.append('id', String(recordId))

  try {
    // The default 20 s timeout aborts a big SVGA mid-upload and shows up as "(canceled)".
    const { data } = await api.post<AnimationUploadResult>(props.uploadUrl, body, { timeout: 300_000 })
    emit('update:modelValue', data.url)
    ElMessage.success('Uploaded and saved')
    emit('saved')
    return data.url
  } catch (e) {
    // A 422's real reason ("larger than 50 MB") is in the field error, not the envelope message.
    if (e instanceof ApiError) ElMessage.error(e.fieldErrors.file ?? e.message)
    return null
  } finally {
    uploading.value = false
  }
}

/** Called by the parent right after it creates the record, now that an id exists. */
async function uploadNow(recordId: number): Promise<string | null> {
  if (!pendingFile.value) return null

  // Cleared either way: on failure the item already exists, so a retry is a fresh pick.
  const url = await doUpload(pendingFile.value, recordId)
  revokePending()
  return url
}

function onUrlInput(value: string) {
  revokePending()
  emit('update:modelValue', value)
}

onBeforeUnmount(revokePending)

defineExpose({ uploadNow, hasPendingFile })
</script>

<template>
  <div>
    <label class="eyebrow mb-1 block">{{ label }}</label>
    <div class="flex gap-2">
      <el-input
        :model-value="modelValue"
        placeholder="https://… or upload a file"
        clearable
        @update:model-value="onUrlInput"
      />
      <el-button :loading="uploading" @click="input?.click()">Upload</el-button>
    </div>

    <p v-if="hasPendingFile" class="eyebrow mt-1 text-[var(--color-signal)]">
      picked {{ pendingFile?.name }} · saves once you create this
    </p>
    <p v-else class="eyebrow mt-1">SVGA, Lottie (.json) or MP4 · up to {{ MAX_MB }} MB</p>

    <div v-if="previewSrc" class="mt-2 flex items-end gap-3">
      <AnimationPreview :key="previewSrc" :src="previewSrc" :type="pendingType" :size="140" />
      <a
        v-if="previewPage && !hasPendingFile"
        :href="previewPage"
        target="_blank"
        rel="noopener"
        class="eyebrow text-[var(--color-signal)] underline"
      >
        open preview in new tab
      </a>
    </div>

    <input ref="input" type="file" accept=".svga,.json,.mp4" class="hidden" @change="onFileChange" />
  </div>
</template>
```

`api` is the project's axios wrapper. It must send `FormData` as multipart, with no forced JSON
content type.

### 2.5 Standalone preview page: `src/views/AnimationPreviewView.vue`

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'

import AnimationPreview from '@/components/AnimationPreview.vue'

/** `/preview?src=<url>` plays an SVGA/MP4 in the browser. Chrome has no SVGA support. */
const route = useRoute()
const src = computed(() => (typeof route.query.src === 'string' ? route.query.src : ''))
const fileName = computed(() => src.value.split('?')[0].split('/').pop() ?? '')
</script>

<template>
  <main class="flex min-h-screen flex-col items-center justify-center gap-4 bg-[var(--color-paper)] p-4">
    <template v-if="src">
      <AnimationPreview :src="src" :size="360" />
      <p class="key max-w-full truncate text-[var(--color-legend)]">{{ fileName }}</p>
      <a :href="src" download class="eyebrow text-[var(--color-signal)] underline">download file</a>
    </template>
    <p v-else class="eyebrow">add ?src=&lt;animation url&gt; to preview a file</p>
  </main>
</template>
```

### 2.6 Router: `src/router/index.ts`

The page must open **with or without sign-in**. The existing `public` flag (used by login) sends a
signed-in admin to the overview, so a separate `open` flag was added:

```ts
declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean
    /** Reachable signed in or not. Unlike `public`, it doesn't send a signed-in admin home. */
    open?: boolean
    // ...
  }
}

// routes: next to /login, outside the AppShell layout
{
  path: '/preview',
  name: 'animation-preview',
  component: () => import('@/views/AnimationPreviewView.vue'),
  meta: { open: true, title: 'Animation preview' },
},

// guard: first line after auth.restore()
router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.ready) await auth.restore()

  if (to.meta.open) return true

  if (to.meta.public) {
    return auth.isAuthenticated ? { name: 'overview' } : true
  }
  // ...
})
```

### 2.7 Store page: `src/views/StoreView.vue`

**Imports + ref:**

```ts
import AnimationPreview from '@/components/AnimationPreview.vue'
import AnimationUpload from '@/components/AnimationUpload.vue'

const animationUpload = ref<InstanceType<typeof AnimationUpload> | null>(null)

/** The card plays the animation when there is one the panel can play (SVGA / MP4). */
function playableAnimation(row: StoreItemRow): string | null {
  const url = row.animation_url
  if (!url) return null
  const ext = url.split('?')[0].split('.').pop()?.toLowerCase()
  return ext === 'svga' || ext === 'mp4' ? url : null
}
```

**In `save()`, after the create call** (next to the image's `uploadNow`):

```ts
const { data } = await api.post<{ id: number }>('/admin/store-items', { ...body, type: form.type })

// From here the item exists. A failed upload must not look like a failed create.
form.id = data.id

const failed: string[] = []
if (imageUpload.value?.hasPendingFile && (await imageUpload.value.uploadNow(data.id)) === null)
  failed.push('image')
if (animationUpload.value?.hasPendingFile && (await animationUpload.value.uploadNow(data.id)) === null)
  failed.push('animation')

if (failed.length) {
  // Keep the dialog open, now editing the new item, so the upload can be retried.
  ElMessage.warning(`Item created, but the ${failed.join(' and ')} did not upload. Pick the file again.`)
  await load()
  return
}

ElMessage.success('Item created')
```

**Dialog.** Replace the plain "Animation URL" `<el-input>` for each type that has an animation:

```vue
<!-- frame -->
<AnimationUpload
  ref="animationUpload"
  v-model="form.animation_url"
  upload-url="/admin/store-items/animation"
  :record-id="form.id"
  @saved="load"
/>

<!-- entrance_effect: also fills the Animation type select -->
<AnimationUpload
  ref="animationUpload"
  v-model="form.animation_url"
  upload-url="/admin/store-items/animation"
  :record-id="form.id"
  @type="form.animation_type = $event"
  @saved="load"
/>
```

Only one of these renders at a time (`v-if` on the type), so sharing the ref name is fine.

**Card thumbnail.** Play the animation and fall back to the image:

```vue
<div class="flex h-20 items-center justify-center ...">
  <AnimationPreview
    v-if="playableAnimation(row)"
    :src="playableAnimation(row)!"
    :size="80"
    bare
  />
  <img v-else-if="row.image_url" :src="row.image_url" alt="" class="h-full w-full object-contain p-2" />
  <svg v-else ...placeholder icon... />
</div>
```

---

## 3. OpenAPI (optional)

Document `POST /admin/store-items/animation` as `multipart/form-data` with `file` (binary, required)
and `id` (integer, nullable). The 200 response `data` has `url`, `path`, `size` and `type`. Return 422
for a file over **50 MB** or one that is not SVGA/JSON/MP4. Stored as `<prefix>/storeItem/{id}/animation_{unix time}.{ext}`.

---

## 4. Checklist to verify

```bash
# backend tests
php artisan test --filter=StoreManagementTest

# admin type-check
npx vue-tsc --noEmit -p tsconfig.app.json

# what URL the disk generates now
php artisan tinker --execute='echo Storage::disk("vultr")->url("guftagu/storeItem/1/animation_1.svga");'
# → https://blr1.vultrobjects.com/space-1/guftagu/storeItem/1/animation_1.svga

# the uploaded object is public and CORS-readable
curl -s -D - -o /dev/null -H "Origin: http://localhost:5173" "<uploaded url>" | grep -i "HTTP\|access-control"
# → 200 OK + access-control-allow-origin: *
```

Manual:
1. Store → Edit a frame → **Upload** a `.svga`. It says "Uploaded and saved", and the preview plays in the dialog.
2. The URL looks like `https://blr1.vultrobjects.com/space-1/guftagu/storeItem/<id>/animation_<time>.svga`.
3. **open preview in new tab** → `/preview?src=…` plays it in Chrome.
4. **Add item** → pick a `.svga` before saving. It previews locally, then uploads into the new id's folder on Save.
5. The store card for that item shows the animation playing.
6. **Add item → Entry Effect** → pick a PNG **and** a large `.svga` (10–50 MB) → Save. Both upload, the
   type select shows "SVGA", and the dialog closes with "Item created".
7. Pick a file **over 50 MB**. It is refused on pick with "That file is N MB. The limit is 50 MB."
8. Make an upload fail after create (e.g. stop the API mid-upload). The dialog stays open on the new
   item with "Item created, but the animation did not upload".

Large-file check through the real stack (browser path: Vite proxy → Laravel → Vultr):

```bash
head -c 20971520 /dev/urandom > big.svga      # 20 MB test file (backend checks extension only)
curl -s -w "
[%{http_code} %{time_total}s]
" -H "Authorization: Bearer <admin token>"   -H "Accept: application/json" -F "file=@big.svga"   http://localhost:5173/api/v1/admin/store-items/animation
# Guftagu: 200 in ~16 s. Delete the uploaded object afterwards.
```

---

## 5. Case study: "Entry Effect upload gets canceled"

**Symptom.** Add item → Entry Effect → pick a PNG and an SVGA → Save. The upload looked "canceled",
no animation was saved, and the panel only said "Validation failed". The Network tab showed:

```json
POST /api/v1/admin/store-items/animation → 422
{ "success": false, "message": "Validation failed",
  "error": { "code": "VALIDATION_ERROR",
             "details": { "file": ["That file is larger than 10 MB. Compress it or shorten the animation."] } } }
```

**How it was diagnosed.**
1. Checked `src/lib/api.ts` for request cancelling. There was none, but it had `timeout: 20_000`.
2. `storage/logs/laravel.log`: MySQL was also down at the time (`SQLSTATE[HY000] [2002] ... refused`).
   Restarted it (XAMPP → MySQL → Start); its error log showed "crash recovery", so it had died uncleanly.
3. Ran the real flow with curl through the Vite proxy (create → image upload → animation upload). It
   passed with a 765 KB SVGA, so the code path was fine and the failure depended on file size.
4. The 422 above confirmed it: the entry-effect SVGA was over the 10 MB cap.
5. Timed a 20 MB upload end-to-end: about 16 s. The 20 s axios timeout would therefore abort anything
   around 25 MB or larger, which shows up as `(canceled)`.

**Root causes → fixes.**

| # | Cause | Fix | Where |
|---|---|---|---|
| 1 | Cap too low for full-screen entry effects | `MAX_ANIMATION_KB` 10240 → **51200** (50 MB) | §1.4 |
| 2 | PHP could time out pushing a big file to Vultr (Windows counts wall-clock) | `set_time_limit(300)` in `uploadAnimation()` | §1.4 |
| 3 | axios 20 s timeout aborted big uploads → `(canceled)` | `{ timeout: 300_000 }` on the animation upload call | §2.4 |
| 4 | Only "Validation failed" was shown | `e.fieldErrors.file ?? e.message` | §2.4 |
| 5 | An oversized file was only rejected after Save | Size check on pick (`MAX_MB = 50`) | §2.4 |
| 6 | Create succeeded, upload failed, dialog still closed | `form.id = data.id`; if an `uploadNow` returns `null`, warn and keep the dialog open | §2.7 |
| 7 | Stale "saves once you create this" after a failed upload | `uploadNow()` clears the pending file either way | §2.4 |

Also bump the test's oversized file from 20 MB to 60 MB (§1.6), or it no longer exceeds the cap.
Keep `upload_max_filesize` / `post_max_size` above 50 MB (Guftagu: 250M / 500M).

If a real file is bigger than 50 MB, raise both `MAX_ANIMATION_KB` and `MAX_MB` together. Every app
user downloads that file before the effect plays, though.

---

## 6. Known leftovers (not done in Guftagu)

- **Gift animation upload** (`/admin/gifts/animation`) still validates with a `mimetypes:` rule that
  has no zip/zlib entry, so a real SVGA may be rejected there. The same fix applies: `extensions:` rule
  plus storing with the original extension.
- Files uploaded before this change keep their old virtual-host URL
  (`https://space-1.blr1.vultrobjects.com/store-animations/…`). They still work. Re-upload to move them
  to the new layout.
- If an SVGA fails to load, the card shows "could not play this SVGA" rather than falling back to the image.
- No Lottie preview (it would need `lottie-web`).
