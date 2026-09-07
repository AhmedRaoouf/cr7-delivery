<?php

namespace App\Http\Controllers\Api\Customer;

use App\Actions\Customer\ScheduleOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ScheduleOrderRequest;
use App\Http\Resources\Shared\ScheduledOrderResource;
use App\Models\ScheduledOrder;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** «طلباتي المجدولة» — the customer's own half of the scheduling feature. */
class ScheduledOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = ScheduledOrder::query()
            ->where('user_id', $request->user()->id)
            ->when($request->string('filter.status')->isNotEmpty(), fn ($q) => $q
                ->where('status', $request->string('filter.status')->value()))
            ->orderBy('scheduled_for')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(ScheduledOrderResource::collection($rows->getCollection()), meta: [
            'current_page' => $rows->currentPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'last_page' => $rows->lastPage(),
        ]);
    }

    public function store(ScheduleOrderRequest $request, ScheduleOrderAction $action): JsonResponse
    {
        return ApiResponse::success(
            new ScheduledOrderResource($action->execute($request->user(), $request->validated())),
            'تم جدولة طلبك — سنرسله في موعده',
            status: 201,
        );
    }

    public function show(Request $request, ScheduledOrder $scheduledOrder): JsonResponse
    {
        abort_unless($scheduledOrder->user_id === $request->user()->id, 404);

        return ApiResponse::success(new ScheduledOrderResource($scheduledOrder));
    }

    /**
     * Cancelled, not deleted.
     *
     * The row is the only record that the customer asked for something and it did not
     * happen, and a support conversation three days later needs it.
     */
    public function destroy(Request $request, ScheduledOrder $scheduledOrder): JsonResponse
    {
        abort_unless($scheduledOrder->user_id === $request->user()->id, 404);

        if (! $scheduledOrder->isPending()) {
            return ApiResponse::error('schedule_not_pending', 'لا يمكن إلغاء هذا الطلب', 422);
        }

        $scheduledOrder->update([
            'status' => ScheduledOrder::STATUS_CANCELLED,
            'cancelled_by' => 'customer',
            'cancelled_at' => now(),
        ]);

        return ApiResponse::success(message: 'تم إلغاء الطلب المجدول');
    }
}
