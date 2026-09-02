<?php

namespace App\Domain\Moderation;

use App\Models\BannedWord;
use App\Models\ContentFlag;
use Illuminate\Support\Facades\Cache;

/**
 * GFT-047 — the content filter (A.5a).
 *
 * Three severities, three different outcomes:
 *
 *   block   → the content is refused outright (422 BANNED_WORD_DETECTED)
 *   replace → the content is delivered with the term swapped, and flagged
 *   flag    → the content is delivered untouched, but flagged for review
 *
 * The list is cached because this runs on **every chat message, room name, bio and DM** —
 * a database round trip per message is not survivable. Any write to the word list flushes
 * it, so a newly banned term bites immediately rather than after the TTL.
 */
class ContentFilter
{
    public const CACHE_KEY = 'cache:banned_words';

    public const TTL = 600;

    public const SEVERITIES = ['block', 'flag', 'replace'];

    public const SCOPES = ['room_name', 'chat', 'bio', 'dm'];

    /**
     * Check a piece of text.
     *
     * @param  string  $scope  one of SCOPES — a word banned in chat may be fine in a bio
     * @return FilterResult
     */
    public function check(string $text, string $scope = 'chat', ?string $language = null): FilterResult
    {
        $matches = [];
        $filtered = $text;
        $worst = null;

        foreach ($this->rules() as $rule) {
            // An empty or absent scope means "everywhere" — narrowing is opt-in, so a
            // word added without thinking about scope is still enforced.
            if (! empty($rule['scope']) && ! in_array($scope, $rule['scope'], true)) {
                continue;
            }

            if ($language !== null && $rule['language'] !== $language && $rule['language'] !== 'any') {
                continue;
            }

            $pattern = $this->patternFor($rule);

            if ($pattern === null || preg_match($pattern, $filtered) !== 1) {
                continue;
            }

            $matches[] = ['word' => $rule['word'], 'severity' => $rule['severity']];

            // block beats replace beats flag: the most restrictive match decides.
            $worst = $this->moreSevere($worst, $rule['severity']);

            if ($rule['severity'] === 'replace') {
                $filtered = preg_replace($pattern, $rule['replacement'] ?? '***', $filtered) ?? $filtered;
            }
        }

        return new FilterResult(
            original: $text,
            filtered: $worst === 'block' ? $text : $filtered,
            severity: $worst,
            matches: $matches,
        );
    }

    /**
     * Check and record. Anything that matched leaves a `content_flags` row — A.5a requires
     * one for `replace`, and a flagged-but-delivered message is useless to a reviewer if
     * nothing wrote it down.
     */
    public function checkAndFlag(
        string $text,
        string $scope = 'chat',
        ?int $userId = null,
        ?string $contentId = null,
        ?string $language = null,
    ): FilterResult {
        $result = $this->check($text, $scope, $language);

        if ($result->matched()) {
            ContentFlag::create([
                'content_type' => $scope,
                'content_id'   => $contentId,
                'user_id'      => $userId,
                'flagged_by'   => 'system',
                'rule_matched' => implode(', ', array_column($result->matches, 'word')),
                'confidence'   => 100,
                // Enough context for a human to judge, not the whole message.
                'excerpt'      => mb_substr($text, 0, 500),
                'status'       => 'open',
            ]);
        }

        return $result;
    }

    /** Flushed on every word-list write, so a new ban takes effect at once. */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function rules(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL, function () {
            return BannedWord::query()
                ->where('is_active', true)
                ->get(['word', 'language', 'severity', 'replacement', 'scope', 'is_regex'])
                ->map(fn (BannedWord $w) => [
                    'word'        => $w->word,
                    'language'    => $w->language,
                    'severity'    => $w->severity,
                    'replacement' => $w->replacement,
                    'scope'       => $w->scope ?? [],
                    'is_regex'    => $w->is_regex,
                ])
                ->all();
        });
    }

    /**
     * Build the match pattern.
     *
     * A plain word is matched on a boundary and case-insensitively, so "class" does not
     * trip a ban on "ass". A regex rule is used as written — but it is validated first,
     * because one bad pattern from the admin panel would otherwise throw on every message
     * the platform handles.
     *
     * The boundary is `(?<!\w)…(?!\w)`, not `\b`. `\b` sits *between* a word character and
     * a non-word one, so a term ending in punctuation can never match: `/\b18\+\b/` demands
     * a word character immediately after the `+`, which "18+ room" does not have — the rule
     * would sit in the list looking enforced and never fire once. The lookarounds say what
     * was actually meant (nothing wordlike may abut the term) and behave identically for
     * ordinary words.
     */
    protected function patternFor(array $rule): ?string
    {
        if (! $rule['is_regex']) {
            return '/(?<!\w)'.preg_quote($rule['word'], '/').'(?!\w)/iu';
        }

        $pattern = '/'.str_replace('/', '\/', $rule['word']).'/iu';

        // @ is deliberate: an invalid pattern must degrade to "no match", never to a
        // fatal error in the middle of a chat send.
        return @preg_match($pattern, '') === false ? null : $pattern;
    }

    protected function moreSevere(?string $current, string $candidate): string
    {
        $rank = ['flag' => 1, 'replace' => 2, 'block' => 3];

        if ($current === null) {
            return $candidate;
        }

        return ($rank[$candidate] ?? 0) > ($rank[$current] ?? 0) ? $candidate : $current;
    }
}
