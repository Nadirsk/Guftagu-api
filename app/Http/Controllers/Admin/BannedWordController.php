<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Moderation\ContentFilter;
use App\Http\Controllers\Controller;
use App\Models\BannedWord;
use App\Models\ContentFlag;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GFT-047 — the banned-word list and what it caught (A.5a). docs/03 §10.1.
 */
class BannedWordController extends Controller
{
    public function __construct(
        protected ContentFilter $filter,
        protected AuditLogger $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'severity' => ['sometimes', 'nullable', Rule::in(ContentFilter::SEVERITIES)],
            'language' => ['sometimes', 'nullable', 'string', 'max:10'],
            'active'   => ['sometimes', 'nullable', 'boolean'],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $paginator = BannedWord::query()
            ->with('createdBy:id,name')
            ->when($data['q'] ?? null, fn ($q, string $t) => $q->where('word', 'like', "%{$t}%"))
            ->when($data['severity'] ?? null, fn ($q, string $s) => $q->where('severity', $s))
            ->when($data['language'] ?? null, fn ($q, string $l) => $q->where('language', $l))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('word')
            ->paginate(
                perPage: (int) ($data['per_page'] ?? 50),
                page: (int) ($data['page'] ?? 1),
            );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(
            fn (BannedWord $w) => $this->payload($w)
        )->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $word = BannedWord::create($data + ['created_by' => $request->user()->id]);
        $this->filter->flush();

        $this->audit->log($request->user(), 'banned_word.create', 'moderation', BannedWord::class, $word->id, null, $data);

        return ApiResponse::success($this->payload($word), 'Word added', 201);
    }

    public function update(Request $request, BannedWord $bannedWord): JsonResponse
    {
        $data = $this->validated($request, $bannedWord);
        $before = $bannedWord->only(array_keys($data));

        $bannedWord->fill($data)->save();
        $this->filter->flush();

        $this->audit->log($request->user(), 'banned_word.update', 'moderation', BannedWord::class, $bannedWord->id, $before, $data);

        return ApiResponse::success($this->payload($bannedWord->refresh()), 'Word updated');
    }

    public function destroy(Request $request, BannedWord $bannedWord): JsonResponse
    {
        $before = $bannedWord->only(['word', 'language', 'severity']);

        $bannedWord->delete();
        $this->filter->flush();

        $this->audit->log($request->user(), 'banned_word.delete', 'moderation', BannedWord::class, $bannedWord->id, $before, null);

        return ApiResponse::success(null, 'Word removed');
    }

    /**
     * Bulk import (A.5a).
     *
     * Reports each row's outcome rather than failing the whole batch on one duplicate —
     * a 400-word paste that dies on line 3 is worse than useless.
     */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'words'       => ['required', 'array', 'min:1', 'max:1000'],
            'words.*'     => ['required', 'string', 'max:100'],
            'language'    => ['sometimes', 'string', 'max:10'],
            'severity'    => ['sometimes', Rule::in(ContentFilter::SEVERITIES)],
            'replacement' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $language = $data['language'] ?? 'en';
        $severity = $data['severity'] ?? 'block';
        $added = 0;
        $skipped = [];

        foreach (array_unique(array_map('trim', $data['words'])) as $raw) {
            if ($raw === '') {
                continue;
            }

            if (BannedWord::where('word', $raw)->where('language', $language)->exists()) {
                $skipped[] = $raw;

                continue;
            }

            BannedWord::create([
                'word'        => $raw,
                'language'    => $language,
                'severity'    => $severity,
                'replacement' => $data['replacement'] ?? ($severity === 'replace' ? '***' : null),
                'is_regex'    => false,
                'is_active'   => true,
                'created_by'  => $request->user()->id,
            ]);

            $added++;
        }

        $this->filter->flush();

        $this->audit->log($request->user(), 'banned_word.import', 'moderation', BannedWord::class, null, null, [
            'added' => $added, 'skipped' => count($skipped),
        ]);

        return ApiResponse::success([
            'added'   => $added,
            'skipped' => $skipped,
            'note'    => $skipped === [] ? null : 'Skipped entries were already on the list for this language.',
        ], "Imported {$added} words");
    }

    /**
     * POST /admin/moderation/filter-test — try a phrase against the live list.
     *
     * Worth having as an endpoint rather than a client-side guess: it runs the *same*
     * `ContentFilter` the platform runs, so what the admin sees here is what will
     * actually happen to a message.
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text'     => ['required', 'string', 'max:2000'],
            'scope'    => ['sometimes', Rule::in(ContentFilter::SCOPES)],
            'language' => ['sometimes', 'nullable', 'string', 'max:10'],
        ]);

        $result = $this->filter->check($data['text'], $data['scope'] ?? 'chat', $data['language'] ?? null);

        return ApiResponse::success($result->toArray() + [
            'outcome' => match ($result->severity) {
                'block'   => 'The message would be refused.',
                'replace' => 'The message would be delivered with the term replaced, and flagged.',
                'flag'    => 'The message would be delivered untouched, and flagged for review.',
                default   => 'The message would pass through untouched.',
            },
            // A dry run must not pollute the review queue.
            'flag_recorded' => false,
        ]);
    }

    /** GET /admin/moderation/flags — what the filter caught and let through. */
    public function flags(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status'       => ['sometimes', 'nullable', Rule::in(['open', 'reviewed', 'dismissed'])],
            'content_type' => ['sometimes', 'nullable', Rule::in(ContentFilter::SCOPES)],
            'page'         => ['sometimes', 'integer', 'min:1'],
            'per_page'     => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = ContentFlag::query()
            ->with(['user:id,guftagu_id', 'reviewedBy:id,name'])
            ->when(
                array_key_exists('status', $data) && $data['status'] !== null,
                fn ($q) => $q->where('status', $data['status']),
                fn ($q) => $q->where('status', 'open'),
            )
            ->when($data['content_type'] ?? null, fn ($q, string $c) => $q->where('content_type', $c))
            ->latest('id')
            ->paginate(
                perPage: (int) ($data['per_page'] ?? 25),
                page: (int) ($data['page'] ?? 1),
            );

        return ApiResponse::paginated($paginator, collect($paginator->items())->map(fn (ContentFlag $f) => [
            'id'           => $f->id,
            'content_type' => $f->content_type,
            'content_id'   => $f->content_id,
            'user'         => $f->user === null ? null : ['id' => $f->user->id, 'guftagu_id' => $f->user->guftagu_id],
            'flagged_by'   => $f->flagged_by,
            'rule_matched' => $f->rule_matched,
            'confidence'   => $f->confidence,
            'excerpt'      => $f->excerpt,
            'status'       => $f->status,
            'reviewed_by'  => $f->reviewedBy?->name,
            'reviewed_at'  => $f->reviewed_at?->toIso8601ZuluString(),
            'created_at'   => $f->created_at?->toIso8601ZuluString(),
        ])->all());
    }

    public function reviewFlag(Request $request, ContentFlag $flag): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['reviewed', 'dismissed'])],
        ]);

        $flag->forceFill([
            'status'      => $data['status'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        return ApiResponse::success(null, 'Flag '.$data['status']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?BannedWord $existing = null): array
    {
        $rules = [
            'word'        => ['required', 'string', 'min:1', 'max:100'],
            'language'    => ['sometimes', 'string', 'max:10'],
            'severity'    => ['required', Rule::in(ContentFilter::SEVERITIES)],
            'replacement' => ['sometimes', 'nullable', 'string', 'max:50'],
            'scope'       => ['sometimes', 'nullable', 'array'],
            'scope.*'     => [Rule::in(ContentFilter::SCOPES)],
            'is_regex'    => ['sometimes', 'boolean'],
            'is_active'   => ['sometimes', 'boolean'],
        ];

        // The unique index is (word, language); validating it here turns a 500 out of the
        // database into a 422 that names the field.
        $rules['word'][] = Rule::unique('banned_words', 'word')
            ->where('language', $request->input('language', $existing->language ?? 'en'))
            ->ignore($existing?->id);

        $data = $request->validate($rules);

        // A pattern that does not compile would silently match nothing forever. Refusing it
        // at the door beats letting an admin believe a rule is live when it never fires.
        if (($data['is_regex'] ?? false) && @preg_match('/'.str_replace('/', '\/', $data['word']).'/iu', '') === false) {
            abort(ApiResponse::error('VALIDATION_ERROR', 'That is not a valid regular expression.', null, 422));
        }

        return $data;
    }

    protected function payload(BannedWord $word): array
    {
        return [
            'id'          => $word->id,
            'word'        => $word->word,
            'language'    => $word->language,
            'severity'    => $word->severity,
            'replacement' => $word->replacement,
            'scope'       => $word->scope ?: [],
            // An empty scope means every surface — spelled out so the UI need not infer it.
            'applies_everywhere' => empty($word->scope),
            'is_regex'    => $word->is_regex,
            'is_active'   => $word->is_active,
            'created_by'  => $word->createdBy?->name,
            'created_at'  => $word->created_at?->toIso8601ZuluString(),
        ];
    }
}
