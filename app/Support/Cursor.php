<?php

namespace App\Support;

/**
 * The opaque cursor behind `meta.next_cursor` (docs/03 §2.3).
 *
 * It carries one thing — the id of the last row on the page — base64url-encoded so it
 * cannot be read as a number and incremented. Feeds and chat are keyset-paginated on
 * `id`, which is what makes a page stable while new rows arrive at the top; `OFFSET 40`
 * on a list that grows shows the same row twice.
 *
 * A malformed cursor decodes to null and the caller starts from the beginning rather than
 * erroring. A pasted or truncated cursor is a client bug, not something worth failing a
 * feed request over.
 */
class Cursor
{
    public static function encode(int $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['id' => $id])), '+/', '-_'), '=');
    }

    public static function decode(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $json = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) && isset($decoded['id']) && is_numeric($decoded['id'])
            ? (int) $decoded['id']
            : null;
    }
}
