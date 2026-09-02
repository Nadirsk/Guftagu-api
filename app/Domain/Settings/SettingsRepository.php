<?php

namespace App\Domain\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Typed, cached access to the `settings` table (docs/02 §13, cache key `cache:settings`
 * with a 600 s TTL per docs/02 §16).
 *
 * Read through this, never through the model directly — a raw read returns a string and
 * `(bool) "0"` is true, which is exactly the bug that would silently disable MFA.
 */
class SettingsRepository
{
    public const CACHE_KEY = 'cache:settings';

    public const TTL = 600;

    /**
     * @return array<string, array{value: ?string, type: string}>
     */
    protected function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL, function () {
            return Setting::query()
                ->get(['key', 'value', 'type'])
                ->keyBy('key')
                ->map(fn (Setting $s) => ['value' => $s->value, 'type' => $s->type])
                ->all();
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->all()[$key] ?? null;

        if ($row === null) {
            return $default;
        }

        return $this->cast($row['value'], $row['type'], $default);
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    public function set(string $key, mixed $value, ?int $updatedBy = null): void
    {
        $existing = Setting::query()->where('key', $key)->first();
        $type     = $existing->type ?? $this->inferType($value);

        Setting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value'      => $this->serialise($value, $type),
                'type'       => $type,
                'group'      => $existing->group ?? 'general',
                'updated_by' => $updatedBy,
            ]
        );

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected function cast(?string $value, string $type, mixed $default): mixed
    {
        if ($value === null) {
            return $default;
        }

        return match ($type) {
            'int'  => (int) $value,
            // "0" and "false" must both be false — the whole reason this class exists.
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default,
            'json' => json_decode($value, true) ?? $default,
            default => $value,
        };
    }

    protected function serialise(mixed $value, string $type): ?string
    {
        return match ($type) {
            'bool'  => $value ? '1' : '0',
            'json'  => json_encode($value),
            default => $value === null ? null : (string) $value,
        };
    }

    protected function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value)              => 'bool',
            is_int($value)               => 'int',
            is_array($value)             => 'json',
            default                      => 'string',
        };
    }
}
