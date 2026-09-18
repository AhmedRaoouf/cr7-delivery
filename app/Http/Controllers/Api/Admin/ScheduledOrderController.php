<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Customer\ReleaseScheduledOrdersAction;
use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Http\Resources\Shared\ScheduledOrderResource;
use App\Models\ScheduledOrder;
use App\Support\AdminScope;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\Lists\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The operator's view of everything waiting on a clock, and the button that skips it. */
class ScheduledOrderController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['scheduled_for', 'created_at', 'estimated_total'], 'scheduled_for');

        $query = AdminScope::applyToScheduledOrders(ScheduledOrder::query(), $request->user())
            ->with(['customer', 'restaurants']);

        $this->whereInList($query, 'scheduled_orders.status', $params->enum('status', [
            ScheduledOrder::STATUS_SCHEDULED, ScheduledOrder::STATUS_RELEASED, ScheduledOrder::STATUS_FAILED, ScheduledOrder::STATUS_CANCELLED,
        ]));
        $this->whereInList($query, 'scheduled_orders.user_id', $params->ids('customer_id'));

        if (($restaurants = $params->ids('restaurant_id')) !== []) {
            $query->whereHas('restaurants', fn ($rq) => $rq->whereIn('restaurants.id', $restaurants));
        }

        $this->whereBetweenDates($query, 'scheduled_orders.scheduled_for', $params->dates());

        ListSearch::apply($query, $params->search(), [
            'refs' => ['reference'],
            'relations' => ['customer' => ['names' => ['name'], 'phones' => ['phone']]],
        ]);

        $rows = $sort->apply($query, [])->paginate($params->perPage());

        return $this->paginated($rows, ScheduledOrderResource::collection($rows->getCollection()), [
            'pending_count' => AdminScope::applyToScheduledOrders(ScheduledOrder::query(), $request->user())->where('status', ScheduledOrder::STATUS_SCHEDULED)->count(),
        ], $sort);
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
