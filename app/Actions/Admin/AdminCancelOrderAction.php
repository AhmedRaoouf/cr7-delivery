<?php

namespace App\Actions\Admin;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\RollupStatus;
use Illuminate\Support\Facades\DB;

/** doc 08 §7 — POST /admin/orders/{id}/cancel: cancel a single sub-order, admin-only (orders.cancel_sub_order). */
class AdminCancelOrderAction
{
    public function execute(User $admin, Order $order, string $reasonCode, ?string $note, bool $refund): Order
    {
        if ($order->status->isTerminal()) {
            throw new ApiException('order_not_cancellable', 'الطلب في حالة نهائية بالفعل', 422);
        }

        return DB::transaction(function () use ($admin, $order, $reasonCode, $note, $refund) {
            $from = $order->status;

            if ($order->driver_id) {
                $order->driver->update(['is_available' => true, 'active_order_id' => null]);
            }

            $order->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_by' => 'admin',
                'cancellation_reason_code' => $reasonCode,
                'cancellation_note' => $note,
                'cancelled_at' => now(),
                'requires_refund' => $refund,
            ]);

            $order->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => OrderStatus::Cancelled->value,
                'actor_type' => OrderStatusActorType::Admin,
                'actor_id' => $admin->id,
                'reason_code' => $reasonCode,
                'note' => $note,
                'meta' => ['message' => 'تدخّلت الإدارة'],
            ]);

            if ($refund && $order->payment_method->value !== 'cash') {
                $payment = $order->orderGroup->payment;

                if ($payment) {
                    $order->refunds()->create([
                        'payment_id' => $payment->id,
                        'amount' => $order->total,
                        'type' => 'full',
                        'reason_code' => $reasonCode,
                        'note' => $note,
                        'status' => 'pending',
                    ]);
                }
            }

            $group = $order->orderGroup;
            $group->update(['rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status'))]);

            AuditLogger::record($admin, 'orders.cancel', $order, ['reason_code' => $reasonCode, 'refund' => $refund]);

            return $order->fresh();
        });
    }
}
