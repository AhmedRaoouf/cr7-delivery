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

            $order->update(['status' => $newStatus, ...self::stampsFor($order, $newStatus, $note)]);

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

    /**
     * The stage column a forced status stands for. Every normal transition writes its
     * `*_at` in the same request; a forced one wrote the status and the history row only,
     * so a forced `delivered` was missing from every report and statement that reads
     * `delivered_at` (settlements, the place's balance, the delivered-time sort) and a
     * forced `cancelled` said nothing about who cancelled it.
     *
     * A column that already holds a time keeps it: that is when the stage really happened,
     * and forcing the same status again must not move it.
     */
    public const STAGE_COLUMNS = [
        'accepted' => 'accepted_at',
        'confirmed' => 'confirmed_at',
        'preparing' => 'preparing_at',
        'ready' => 'ready_at',
        'searching_driver' => 'dispatch_started_at',
        'driver_assigned' => 'driver_assigned_at',
        'arrived_restaurant' => 'arrived_restaurant_at',
        'waiting_at_restaurant' => 'waiting_started_at',
        'picked_up' => 'picked_up_at',
        'arrived_customer' => 'arrived_customer_at',
        'delivered' => 'delivered_at',
        'cancelled' => 'cancelled_at',
    ];

    /** @return array<string, mixed> */
    public static function stampsFor(Order $order, OrderStatus $status, string $note): array
    {
        $changes = [];

        $column = self::STAGE_COLUMNS[$status->value] ?? null;
        if ($column !== null && $order->{$column} === null) {
            $changes[$column] = now();
        }

        if ($status === OrderStatus::Cancelled) {
            if ($order->cancelled_by === null) {
                $changes['cancelled_by'] = 'admin';
            }
            if ($order->cancellation_note === null || $order->cancellation_note === '') {
                $changes['cancellation_note'] = $note;
            }
        }

        return $changes;
    }
}
