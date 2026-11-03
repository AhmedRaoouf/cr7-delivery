<?php

namespace App\Support\Amendments;

use App\Events\OrderChanged;
use App\Models\Order;
use App\Support\BoardQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * doc 99 D-172 — an amendment moves a card between «جديد» and «بانتظار العميل» without
 * moving its status, and `OrderObserver` announces only status and captain changes. So the
 * move is announced here, after the commit, with the two columns named: the board refetches
 * both. A broadcast failure is never an amendment failure — the board's poll is the net.
 */
final class BoardSignal
{
    public static function changed(?Order $order): void
    {
        if ($order === null || config('broadcasting.default') === 'null') {
            return;
        }

        $status = $order->status instanceof \BackedEnum ? $order->status->value : (string) $order->status;
        $queue = BoardQueue::forOrder($order);

        try {
            broadcast(new OrderChanged(
                orderId: $order->id,
                orderNumber: $order->order_number,
                restaurantId: (int) $order->restaurant_id,
                orderGroupId: $order->order_group_id === null ? null : (int) $order->order_group_id,
                driverId: $order->driver_id === null ? null : (int) $order->driver_id,
                cityId: $order->city_id === null ? null : (int) $order->city_id,
                status: $status,
                previousStatus: $status,
                queue: $queue,
                previousQueue: $queue === 'awaiting_customer' ? 'new' : 'awaiting_customer',
            ));
        } catch (Throwable $e) {
            Log::warning('amendment broadcast failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
