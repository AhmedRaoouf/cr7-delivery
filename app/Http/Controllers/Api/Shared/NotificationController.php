<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §4 / §6 — GET /notifications, POST /notifications/{id}/read, /read-all, GET /unread-count. Identical for every client, scoped to the caller. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()->notifications()
            ->when($request->boolean('filter.unread'), fn ($q) => $q->whereNull('read_at'))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($notifications->items(), meta: [
            'current_page' => $notifications->currentPage(),
            'per_page' => $notifications->perPage(),
            'total' => $notifications->total(),
            'last_page' => $notifications->lastPage(),
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);

        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return ApiResponse::success($notification->fresh());
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::success(message: 'تم تحديد كل الإشعارات كمقروءة');
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success(['count' => $request->user()->notifications()->whereNull('read_at')->count()]);
    }
}
