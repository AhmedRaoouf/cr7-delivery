<?php

namespace App\Actions\Driver;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Support\DispatchService;
use App\Support\RollupStatus;
use Illuminate\Support\Facades\DB;

/** doc 05 §6 — allowed only up to waiting_at_restaurant; blocked once picked_up. Same round, driver excluded. */
class CancelTripAction
{
    public function __construct(private readonly DispatchService $dispatch) {}

    public function execute(Driver $driver, Order $order, string $reasonCode, ?string $note): Order
    {
        abort_unless($order->driver_id === $driver->id, 404);

        if (! in_array($order->status, [OrderStatus::DriverAssigned, OrderStatus::ArrivedRestaurant, OrderStatus::WaitingAtRestaurant], true)) {
            throw new ApiException('order_already_picked_up', 'لا يمكن إلغاء الرحلة بعد استلام الطلب', 422);
        }

        $round = $order->dispatch_round;

        DB::transaction(function () use ($driver, $order, $reasonCode, $note) {
            $from = $order->status;

            // Every row this captain has on the order, so the holder rule of doc 63 §1.2
            // reads them as having stepped off it — the dispatch below must not hand it
            // straight back to them as the captain of its basket.
            $order->driverAttempts()->where('driver_id', $driver->id)->where('order_id', $order->id)
                ->update(['result' => 'cancelled_after_accept', 'reason_code' => $reasonCode, 'responded_at' => now()]);

            $driver->increment('cancelled_orders');

            // The rest of the trip stays theirs. Their focus and «carries nothing» are
            // re-derived by `DriverTrip::refresh` on the write below (`OrderObserver`).
            //
            // The search starts again, so the two-minute clock does too: this order has
            // been without a captain for zero seconds, not since it was first made ready.
            $order->update([
                'driver_id' => null,
                'status' => OrderStatus::SearchingDriver,
                'dispatch_started_at' => now(),
                'dispatch_admin_notified_at' => null,
            ]);

            $order->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => OrderStatus::SearchingDriver->value,
                'actor_type' => OrderStatusActorType::Driver,
                'actor_id' => $driver->user_id,
                'reason_code' => $reasonCode,
                'note' => $note,
            ]);

            $group = $order->orderGroup;
            $group->update(['rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status'))]);
        });

        $this->dispatch->dispatchRound($order->fresh(), $round);

        // The captain who stepped off this order may have room again, so they get the
        // rest of the backlog rather than sitting idle until a sweep. Their own
        // `cancelled_after_accept` row keeps this order from coming back to them.
        $this->dispatch->offerBacklogTo($driver);

        return $order->fresh();
    }
}
