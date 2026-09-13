<?php

namespace App\Actions\Admin;

use App\Actions\Driver\DeliverOrderAction;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Models\Order;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\RollupStatus;
use Illuminate\Support\Facades\DB;

/** doc 08 §7 — POST /admin/orders/{id}/force-status: super-admin only, fully audited, bypasses the normal state machine. */
class ForceStatusAction
{
    public function execute(User $admin, Order $order, OrderStatus $newStatus, string $note): Order
    {
        /*
         * doc 62 §4.6 — forcing `delivered` wrote the status and nothing else: no
         * earning, no cash row, no pickup advance. A captain who had carried the food and
         * collected the money was paid for it by nobody, and the cash he was holding was
         * on no meter. With a captain on the order, `delivered` goes through the same
         * completion the captain's own code does.
         */
        if ($newStatus === OrderStatus::Delivered && $order->driver !== null && $order->status !== OrderStatus::Delivered) {
            return DB::transaction(function () use ($admin, $order, $note) {
                $from = $order->status;
                $delivered = DeliverOrderAction::complete($order->driver, $order, null, OrderStatusActorType::Admin, $admin->id);

                $delivered->statusHistory()->latest('id')->first()?->update([
                    'reason_code' => 'force_status',
                    'note' => $note,
                    'meta' => ['message' => 'تدخّلت الإدارة'],
                ]);

                AuditLogger::record($admin, 'orders.force_status', $delivered, ['from' => $from->value, 'to' => 'delivered', 'note' => $note], ['status' => $from->value]);

                return $delivered;
            });
        }

        return DB::transaction(function () use ($admin, $order, $newStatus, $note) {
            $from = $order->status;

            $order->update(['status' => $newStatus]);

            $order->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => $newStatus->value,
                'actor_type' => OrderStatusActorType::Admin,
                'actor_id' => $admin->id,
                'reason_code' => 'force_status',
                'note' => $note,
                'meta' => ['message' => 'تدخّلت الإدارة'],
            ]);

            $group = $order->orderGroup;
            $group->update(['rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status'))]);

            AuditLogger::record($admin, 'orders.force_status', $order, ['from' => $from->value, 'to' => $newStatus->value, 'note' => $note], ['status' => $from->value]);

            return $order->fresh();
        });
    }
}
