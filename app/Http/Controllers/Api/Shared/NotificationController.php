<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §4 / §6 — GET /notifications, POST /notifications/{id}/read, /read-all, GET /unread-count. Identical for every client, scoped to the caller. */
class NotificationController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at'], '-created_at');

        $query = $request->user()->notifications()->getQuery();

        if (($unread = $params->bool('unread')) !== null) {
            $unread ? $query->whereNull('read_at') : $query->whereNotNull('read_at');
        }

        $this->whereInList($query, 'type', $params->list('type'));

        $notifications = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated($notifications, sort: $sort);
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
