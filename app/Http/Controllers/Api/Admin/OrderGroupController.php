<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderGroup;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\RollupStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 — POST /admin/order-groups/{id}/recalculate (doc 04 §6.4 — money on partial cancellation). */
class OrderGroupController extends Controller
{
    public function recalculate(Request $request, OrderGroup $orderGroup): JsonResponse
    {
        $orders = $orderGroup->orders()->get();
        $surviving = $orders->reject(fn ($o) => $o->status->value === 'cancelled');

        $subtotal = round((float) $surviving->sum('subtotal'), 2);
        $discountAmount = round((float) $surviving->sum('discount_share'), 2);
        $deliveryFee = round((float) $surviving->sum('delivery_fee_share'), 2);
        $total = round($subtotal - $discountAmount + $deliveryFee, 2);

        $orderGroup->update([
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'delivery_fee' => $deliveryFee,
            'total' => $total,
            'restaurant_count' => $surviving->count(),
            'rollup_status' => RollupStatus::compute($orders->pluck('status')),
        ]);

        foreach ($surviving as $order) {
            $order->statusHistory()->create([
                'from_status' => $order->status->value,
                'to_status' => $order->status->value,
                'actor_type' => 'admin',
                'actor_id' => $request->user()->id,
                'reason_code' => 'group_recalculated',
                'meta' => ['message' => 'تدخّلت الإدارة'],
            ]);
        }

        AuditLogger::record($request->user(), 'orders.recalculate_group', $orderGroup, ['total' => $total]);

        return ApiResponse::success($orderGroup->fresh(), 'تم إعادة احتساب مجموعة الطلب بنجاح');
    }
}
