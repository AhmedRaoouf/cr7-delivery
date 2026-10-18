<?php

namespace App\Actions\Driver;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderDriverAttempt;
use App\Support\DispatchService;
use App\Support\DriverTrip;
use App\Support\TripAssignment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * doc 05 §4 — the race condition. The database decides who gets the order, not the app.
 *
 * doc 63 changes what is being raced for. **The basket** (D-115): accepting any sub-order
 * takes every sibling already looking for a captain in the same transaction, so the lock is
 * the basket's — two captains pressing «قبول» on the two siblings of one basket in the same
 * second is the race, and the second finds its order already assigned. **The trip**
 * (D-116): a captain carrying orders may accept another when it fits — capacity and
 * distance — and is refused in words when it does not. `already_on_a_trip` is retired from
 * this path; the code stays reserved so a 1.0.3 app that switches on it does not crash.
 */
class AcceptOfferAction
{
    public function __construct(private readonly DispatchService $dispatch) {}

    public function execute(Driver $driver, int $orderId): Order
    {
        $groupId = Order::query()->findOrFail($orderId)->order_group_id;

        $lock = Cache::store('database')->lock($groupId !== null ? "order_group:{$groupId}:assign" : "order:{$orderId}:assign", 5);

        if (! $lock->get()) {
            throw new ApiException('already_assigned', 'تم قبول الطلب بواسطة كابتن آخر', 409);
        }

        try {
            $order = DB::transaction(function () use ($driver, $orderId) {
                // This captain's row first: two of their own accepts on two different
                // baskets must not both pass the capacity check before either commits.
                $captain = Driver::query()->lockForUpdate()->findOrFail($driver->id);
                $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();

                if ($order->driver_id !== null) {
                    throw new ApiException('already_assigned', 'تم قبول الطلب بواسطة كابتن آخر', 409);
                }

                if ($order->status !== OrderStatus::SearchingDriver) {
                    throw new ApiException('offer_expired', 'انتهت صلاحية هذا العرض', 422);
                }

                $attempt = $captain->driverAttempts()->where('order_id', $order->id)->where('result', 'sent')->first();

                if (! $attempt) {
                    throw new ApiException('not_eligible', 'هذا العرض غير متاح لك', 403);
                }

                // Gates 2–4. Distance between the places is the operator's call on an
                // order they handed this captain by name (doc 63 §3.6).
                $refusal = DriverTrip::refusal($captain, $order, enforceRadius: $attempt->assigned_by === null);

                if ($refusal !== null) {
                    throw new ApiException($refusal, DriverTrip::refusalMessage($refusal), 409, meta: [
                        'trip_count' => DriverTrip::count($captain),
                        'trip_max' => DriverTrip::max(),
                    ]);
                }

                TripAssignment::assign($order, $captain, OrderStatusActorType::Driver, $captain->user_id, attempt: $attempt);

                $this->takeTheBasket($captain, $order);
                $this->recheckOtherOffers($captain, $order);

                return $order->fresh();
            });
        } finally {
            $lock->release();
        }

        // After the commit, never inside it: offer rows for other orders would hold their
        // locks across this one. A captain who has just taken an order hears about the
        // waiting orders around that place in the same breath (doc 63 §2.2).
        $this->dispatch->offerBacklogTo($driver);

        return $order;
    }

    /**
     * D-115 — every sibling already looking for a captain comes along; siblings still in
     * the kitchen reach this captain through the ready rule when they are ready. A sibling
     * this captain once refused or stepped off is left to dispatch, as the holder rule
     * would leave it.
     */
    private function takeTheBasket(Driver $captain, Order $order): void
    {
        if (! DriverTrip::oneCaptainPerBasket()) {
            return;
        }

        $siblings = Order::query()
            ->where('order_group_id', $order->order_group_id)
            ->whereKeyNot($order->id)
            ->whereNull('driver_id')
            ->whereIn('status', [OrderStatus::SearchingDriver->value, OrderStatus::WaitingForDriver->value])
            ->lockForUpdate()
            ->get();

        foreach ($siblings as $sibling) {
            $refused = $sibling->driverAttempts()
                ->where('driver_id', $captain->id)
                ->whereIn('result', DriverTrip::EXCLUDING_RESULTS)
                ->exists();

            if ($refused) {
                continue;
            }

            TripAssignment::assign(
                $sibling,
                $captain,
                OrderStatusActorType::Driver,
                $captain->user_id,
                attempt: $captain->driverAttempts()->where('order_id', $sibling->id)->where('result', 'sent')->first(),
                history: ['reason_code' => 'basket'],
            );
        }
    }

    /**
     * doc 63 §2.2 — this captain's other open offers are re-checked against the trip they
     * now carry: those that no longer fit are superseded, those that still fit stay live.
     * «يجيله أكتر من order مع بعض».
     */
    private function recheckOtherOffers(Driver $captain, Order $order): void
    {
        $open = OrderDriverAttempt::query()
            ->where('driver_id', $captain->id)
            ->where('result', 'sent')
            ->whereHas('order', fn ($q) => $q->where('order_group_id', '!=', $order->order_group_id))
            ->with('order')
            ->get();

        foreach ($open as $attempt) {
            $other = $attempt->order;

            $fits = $other !== null
                && $other->driver_id === null
                && DriverTrip::refusal($captain, $other, enforceRadius: $attempt->assigned_by === null) === null;

            if (! $fits) {
                $attempt->update(['result' => 'superseded', 'responded_at' => now()]);
            }
        }
    }
}
