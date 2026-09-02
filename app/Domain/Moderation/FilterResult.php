<?php

namespace App\Domain\Moderation;

/**
 * The outcome of a content check.
 *
 * `filtered` is what should actually be delivered — for a `block` it is left as the
 * original, because the caller is expected to refuse the content rather than send a
 * mangled version of it.
 */
class FilterResult
{
    public function __construct(
        public readonly string $original,
        public readonly string $filtered,
        /** block | replace | flag | null when nothing matched */
        public readonly ?string $severity,
        /** @var array<int, array{word: string, severity: string}> */
        public readonly array $matches,
    ) {
    }

    public function matched(): bool
    {
        return $this->severity !== null;
    }

    /** The caller must refuse the content — A.5a's 422 BANNED_WORD_DETECTED. */
    public function blocked(): bool
    {
        return $this->severity === 'block';
    }

    public function wasReplaced(): bool
    {
        return $this->severity === 'replace' && $this->filtered !== $this->original;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'matched'      => $this->matched(),
            'blocked'      => $this->blocked(),
            'severity'     => $this->severity,
            'original'     => $this->original,
            'filtered'     => $this->filtered,
            'was_replaced' => $this->wasReplaced(),
            'matches'      => $this->matches,
        ];
    }
}
