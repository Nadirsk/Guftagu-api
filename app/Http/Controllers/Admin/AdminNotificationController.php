<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The admin panel's own notification inbox — C.5a and general use across all four roles.
 *
 * `Notification` already gets written to (support escalation, for one — see
 * `SupportService::escalate()`), but nothing ever read it back for the panel: there was no
 * bell, and no endpoint for one to call. No permission key here, on purpose — every screen
 * this exposes is the caller's own inbox, never someone else's or a platform-wide action.
 */
class AdminNotificationController extends Controller
{
    /** GET /admin/notifications */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'unread'   => ['sometimes', 'boolean'],
        ]);

        $base = Notification::query()->where('admin_user_id', $request->user()->id);

        $paginator = (clone $base)
            ->when($data['unread'] ?? false, fn ($q) => $q->unread())
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
                // The badge count needs the true unread total, not the size of one page.
                'unread_count' => (clone $base)->unread()->count(),
            ],
        );
    }

    /** POST /admin/notifications/{notification}/read */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->admin_user_id !== $request->user()->id) {
            return ApiResponse::error('FORBIDDEN', 'That notification belongs to another admin.', null, 403);
        }

        if (! $notification->is_read) {
            $notification->update(['is_read' => true, 'read_at' => now()]);
        }

        return ApiResponse::success($this->payload($notification->fresh()));
    }

    /** POST /admin/notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        Notification::query()
            ->where('admin_user_id', $request->user()->id)
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
            'deep_link'  => $notification->deep_link,
            'is_read'    => $notification->is_read,
            'read_at'    => $notification->read_at?->toIso8601ZuluString(),
            'created_at' => $notification->created_at?->toIso8601ZuluString(),
        ];
    }
}
