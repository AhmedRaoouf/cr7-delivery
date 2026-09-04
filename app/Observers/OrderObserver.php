<?php

namespace App\Observers;

use App\Events\OrderChanged;
use App\Models\Order;
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
        $this->announce($order, null);
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged(self::WATCHED)) {
            return;
        }

        $previous = $order->getOriginal('status');
        $previousStatus = match (true) {
            $previous instanceof \BackedEnum => (string) $previous->value,
            $previous === null => null,
            default => (string) $previous,
        };

        $this->announce($order, $previousStatus);
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
