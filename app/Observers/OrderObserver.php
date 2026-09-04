<?php

namespace App\Observers;

use App\Events\OrderChanged;
use App\Models\Order;
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
}
