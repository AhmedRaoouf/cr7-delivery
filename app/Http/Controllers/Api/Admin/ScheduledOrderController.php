<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Customer\ReleaseScheduledOrdersAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\ScheduledOrderResource;
use App\Models\ScheduledOrder;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The operator's view of everything waiting on a clock, and the button that skips it. */
class ScheduledOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = AdminScope::applyToScheduledOrders(ScheduledOrder::query(), $request->user())
            ->with(['customer', 'restaurants'])
            ->when($request->string('filter.status')->isNotEmpty(), fn ($q) => $q
                ->where('status', $request->string('filter.status')->value()))
            ->when($request->integer('filter.restaurant_id'), fn ($q, $id) => $q
                ->whereHas('restaurants', fn ($rq) => $rq->where('restaurants.id', $id)))
            ->orderBy('scheduled_for')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(ScheduledOrderResource::collection($rows->getCollection()), meta: [
            'current_page' => $rows->currentPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'last_page' => $rows->lastPage(),
            'pending_count' => AdminScope::applyToScheduledOrders(ScheduledOrder::query(), $request->user())->where('status', ScheduledOrder::STATUS_SCHEDULED)->count(),
        ]);
    }

    public function show(Request $request, ScheduledOrder $scheduledOrder): JsonResponse
    {
        $this->ensureInScope($request, $scheduledOrder);

        return ApiResponse::success(new ScheduledOrderResource($scheduledOrder->load(['customer', 'restaurants'])));
    }

    /** «أرسله الآن» — release ahead of the clock, at the operator's own risk. */
    public function release(Request $request, ScheduledOrder $scheduledOrder, ReleaseScheduledOrdersAction $action): JsonResponse
    {
        $this->ensureInScope($request, $scheduledOrder);

        if (! $scheduledOrder->isPending()) {
            return ApiResponse::error('schedule_not_pending', 'هذا الطلب لم يعد في انتظار موعده', 422);
        }

        $released = $action->release($scheduledOrder);

        AuditLogger::record($request->user(), 'scheduled_orders.release', $released);

        return ApiResponse::success(new ScheduledOrderResource($released), 'تم إرسال الطلب إلى المطاعم');
    }

    public function cancel(Request $request, ScheduledOrder $scheduledOrder): JsonResponse
    {
        $this->ensureInScope($request, $scheduledOrder);

        if (! $scheduledOrder->isPending()) {
            return ApiResponse::error('schedule_not_pending', 'هذا الطلب لم يعد في انتظار موعده', 422);
        }

        $scheduledOrder->update([
            'status' => ScheduledOrder::STATUS_CANCELLED,
            'cancelled_by' => 'admin',
            'cancelled_at' => now(),
        ]);

        AuditLogger::record($request->user(), 'scheduled_orders.cancel', $scheduledOrder);

        return ApiResponse::success(message: 'تم إلغاء الطلب المجدول');
    }

    /** Batch 67 — a scheduled basket follows its delivery address's scope (D-52). */
    private function ensureInScope(Request $request, ScheduledOrder $scheduledOrder): void
    {
        AdminScope::abortUnlessVisible(AdminScope::applyToScheduledOrders(ScheduledOrder::query(), $request->user()), $scheduledOrder);
    }
}
