<?php

namespace App\Observers;

use App\Events\OrderChanged;
use App\Models\Order;
use App\Support\DriverTrip;
use App\Support\Notifications\OrderNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single place an order movement becomes a broadcast.
 *
 * There is no central transition service in this application — `status` is written
 * from nineteen call sites across controllers, actions and `DispatchService`. Hooking
 * each of them would have meant nineteen chances to forget one, and a forgotten one
 * shows up as a board that silently stops updating for that transition only. The
 * model's own `updated` event cannot be bypassed by any of them.
 *
 * Two things this class is careful about:
 *
 * 1. **Nothing is announced before it is committed.** Most status writes sit inside a
 *    `DB::transaction`; broadcasting a move that then rolls back would leave every open
 *    board showing a state the database never reached. The deferral is done here rather
 *    than with `ShouldDispatchAfterCommit` on the event, because a failure inside the
 *    commit callback has to be caught here — see (2).
 *
 * 2. **A broadcast failure is never an order failure.** Pusher being unreachable must
 *    not roll back an accept, a delivery or a refund. It degrades the dashboards to
 *    their polling safety net, which is exactly the behaviour before this file existed.
 *
 * The same two properties are why doc 11's notifications hang here too. A push and a
 * broadcast answer different questions — «wake the screen that is already open» versus
 * «tell the person whose phone is in their pocket» — but they are owed by the same
 * nineteen writes, at the same moment, under the same two rules. Announcing them from
 * one place is the only way the two can never disagree about what happened.
 */
class OrderObserver
{
    /** Columns whose change is worth waking every open screen for. */
    private const WATCHED = ['status', 'driver_id'];

    public function created(Order $order): void
    {
        // Only a seeded or imported order is born with a captain; it is on their trip now.
        if ($order->driver_id !== null) {
            DriverTrip::refresh((int) $order->driver_id);
        }

        $this->announce($order, null);
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged(self::WATCHED)) {
            return;
        }

        $this->refreshTrips($order);

        $previous = $order->getOriginal('status');
        $previousStatus = match (true) {
            $previous instanceof \BackedEnum => (string) $previous->value,
            $previous === null => null,
            default => (string) $previous,
        };

        $this->announce($order, $previousStatus);
    }

    public function deleted(Order $order): void
    {
        if ($order->driver_id !== null) {
            DriverTrip::refresh((int) $order->driver_id);
        }
    }

    /**
     * doc 63 D-117 — the trip is derived, so every write that moves an order's status or
     * captain re-derives the focus and «carries nothing» for the captains on either side
     * of it. The same argument as the broadcast below: hooking the nineteen call sites
     * that write `status` was nineteen chances to forget one, and a forgotten one is a
     * captain whose `active_order_id` names an order they no longer carry. Not wrapped:
     * it is a row in the same transaction, and a failure here should fail the write.
     *
     * The observer does not see a query-builder write. None of `orders.status` or
     * `orders.driver_id` is written that way today; one that ever is has to call
     * {@see DriverTrip::refresh()} itself.
     */
    private function refreshTrips(Order $order): void
    {
        $drivers = array_unique(array_filter([
            $order->driver_id === null ? null : (int) $order->driver_id,
            $order->getOriginal('driver_id') === null ? null : (int) $order->getOriginal('driver_id'),
        ]));

        foreach ($drivers as $driverId) {
            DriverTrip::refresh($driverId);
        }
    }

    private function announce(Order $order, ?string $previousStatus): void
    {
        $this->notify($order, $previousStatus);

        if (config('broadcasting.default') === 'null') {
            return;
        }

        $send = function () use ($order, $previousStatus): void {
            try {
                broadcast(OrderChanged::fromOrder($order, $previousStatus));
            } catch (Throwable $e) {
                Log::warning('order broadcast failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($send);

            return;
        }

        $send();
    }

    /**
     * doc 11's rows for this transition.
     *
     * Not deferred to after the commit the way the broadcast is, and the
     * difference is deliberate: the `notifications` row belongs *in* the
     * transaction, so an accept that rolls back takes «وافق المطعم» with it.
     * Only the push leaves the transaction, and `NotificationDispatcher` defers
     * that itself.
     *
     * Wrapped all the same. A notification is worth less than an order, and the
     * failure this catches is the one where a customer cannot be delivered to
     * because a copy template referenced a column that is null.
     */
    private function notify(Order $order, ?string $previousStatus): void
    {
        try {
            app(OrderNotifications::class)->handle($order, $previousStatus);
        } catch (Throwable $e) {
            Log::warning('order notification failed', [
                'order_id' => $order->id,
                'status' => $order->status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
