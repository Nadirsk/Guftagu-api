<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * docs/03 §2.1 — "Every response, success or failure, has the same shape. No exceptions."
 *
 * Build every controller response through here so `meta.request_id` and
 * `meta.timestamp` are never forgotten.
 */
class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'meta'    => static::meta($meta),
        ], $status);
    }

    public static function error(string $code, string $message, mixed $details = null, int $status = 400, array $meta = []): JsonResponse
    {
        $error = ['code' => $code];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'success' => false,
            'message' => $message,
            'error'   => $error,
            'meta'    => static::meta($meta),
        ], $status);
    }

    /**
     * Offset pagination for admin tables (docs/03 §2.3).
     */
    public static function paginated(LengthAwarePaginator $paginator, mixed $items = null, string $message = 'OK'): JsonResponse
    {
        return static::success($items ?? $paginator->items(), $message, 200, [
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'last_page'    => $paginator->lastPage(),
        ]);
    }

    protected static function meta(array $extra = []): array
    {
        return array_merge([
            'request_id' => request()->attributes->get('request_id') ?? (string) str()->ulid(),
            'timestamp'  => now()->toIso8601ZuluString(),
        ], $extra);
    }
}
