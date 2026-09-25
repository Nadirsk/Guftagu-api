<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile user notification inbox.
 * Shows likes, comments, follows, and other in-app alerts.
 */
class NotificationController extends Controller
{
    /** GET /api/v1/notifications */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $unread = $request->has('unread') ? $request->boolean('unread') : false;

        $base = Notification::query()->where('user_id', $request->user()->id);

        $paginator = (clone $base)
            ->when($unread, fn ($q) => $q->unread())
            ->latest('id')
            ->paginate(
                perPage: (int) ($data['per_page'] ?? 20),
                page: (int) ($data['page'] ?? 1),
            );

        return ApiResponse::success(
            collect($paginator->items())->map(fn (Notification $n) => $this->payload($n))->all(),
            'OK',
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
                'unread_count' => (clone $base)->unread()->count(),
            ],
        );
    }

    /** GET /api/v1/notifications/unread-count */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::query()
            ->where('user_id', $request->user()->id)
            ->unread()
            ->count();

        return ApiResponse::success(['unread_count' => $count]);
    }

    /** POST /api/v1/notifications/{notification}/read */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return ApiResponse::error('FORBIDDEN', 'That notification belongs to another user.', null, 403);
        }

        if (! $notification->is_read) {
            $notification->update(['is_read' => true, 'read_at' => now()]);
        }

        return ApiResponse::success($this->payload($notification->fresh()));
    }

    /** POST /api/v1/notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        Notification::query()
            ->where('user_id', $request->user()->id)
            ->unread()
            ->update(['is_read' => true, 'read_at' => now()]);

        return ApiResponse::success(['marked' => true]);
    }

    /** @return array<string, mixed> */
    protected function payload(Notification $notification): array
    {
        return [
            'id'         => $notification->id,
            'type'       => $notification->type,
            'title'      => $notification->title,
            'body'       => $notification->body,
            'data'       => $notification->data,
            'image_url'  => $notification->image_url,
            'deep_link'  => $notification->deep_link,
            'is_read'    => (bool) $notification->is_read,
            'read_at'    => $notification->read_at?->toIso8601ZuluString(),
            'created_at' => $notification->created_at?->toIso8601ZuluString(),
        ];
    }
}
