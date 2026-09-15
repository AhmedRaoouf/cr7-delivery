<?php

namespace App\Actions\Admin;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Events\DriverOfferSent;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Notifications\OfferContext;
use App\Support\RollupStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * An assignment the captain can refuse.
 *
 * Operator, 2026-09-08: «الوقت أنا بعمل إسناد لكابتن للأوردر، المفروض يجيله notification
 * اللي هو قبول ورفض، وبعد كده لو رفض يروح للأدمن إن هو رفض».
 *
 * [ReassignDriverAction] does the other thing and still does it: it writes
 * `result = accepted` on the captain's behalf, moves the order to `driver_assigned` and
 * tells nobody. That is a fine tool for a captain who is standing in the office, and a
 * bad one for a captain whose phone is in a pocket — the order was theirs, they never
 * knew, and it went nowhere until an operator noticed a second time.
 *
 * So this is an **offer with a name on it**: the same `order_driver_attempts` row a
 * dispatch round writes, plus `assigned_by`. The captain sees the ordinary `DRV-13`
 * accept/reject screen, `AcceptOfferAction` decides the race exactly as it always has,
 * and [RejectOfferAction] reads `assigned_by` to tell the operator it came back.
 *
 * The order is **not** held for them. It returns to the pool the instant it is offered,
 * so `cr7:tick`'s widening sweep keeps looking while the named captain thinks about it.
 * An offer that nobody answers must never be the reason an order stops moving — that is
 * the defect the whole 2026-09-08 pass exists to close.
 */
class OfferOrderToDriverAction
{
    /** Past this the captain is holding the food; changing captains is a different problem. */
    private const BLOCKED_FROM = ['picked_up', 'on_the_way', 'arrived_customer', 'delivered', 'cancelled'];

    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public function execute(User $admin, Order $order, int $driverId, string $reasonCode, ?string $note): Order
    {
        if (in_array($order->status->value, self::BLOCKED_FROM, true)) {
            throw new ApiException('invalid_transition', 'لا يمكن تغيير الكابتن بعد استلام الطلب', 422);
        }

        $driver = Driver::query()->where('is_active', true)->findOrFail($driverId);

        // doc 63 §3.6 — room on their trip, not an empty hand, and not mid-errand.
        // Distance is the operator's call: the captain's accept skips that gate for an
        // offer with `assigned_by` on it.
        ReassignDriverAction::assertTheCaptainCanTakeIt($driver, $order);

        $order = DB::transaction(function () use ($admin, $order, $driver, $reasonCode, $note) {
            $previous = $order->driver;

            // Released now rather than on acceptance. Holding the order for a captain
            // who may never answer is what «إسناد» used to mean, and it is what left
            // orders parked against an unreachable phone. Their trip is re-derived by
            // `OrderObserver` when `driver_id` is cleared below.
            if ($previous !== null && $previous->id !== $driver->id) {
                $order->driverAttempts()
                    ->where('driver_id', $previous->id)
                    ->where('result', 'accepted')
                    ->update(['result' => 'reassigned_by_admin', 'reason_code' => $reasonCode, 'responded_at' => now()]);
            }

            // An operator who assigns twice must not create two live offers to the same
            // captain; the second replaces the first.
            $order->driverAttempts()
                ->where('driver_id', $driver->id)
                ->where('result', 'sent')
                ->update(['result' => 'superseded', 'responded_at' => now()]);

            $from = $order->status;

            $order->driverAttempts()->create([
                'driver_id' => $driver->id,
                'round' => $order->dispatch_round + 1,
                // The offer card reads this; an assignment is still a distance away.
                'distance_km' => OfferContext::toPlaceKm($order, $driver),
                'result' => 'sent',
                'reason_code' => $reasonCode,
                'assigned_by' => $admin->id,
                'sent_at' => now(),
                'expires_at' => null,
            ]);

            $order->update([
                'driver_id' => null,
                'status' => OrderStatus::SearchingDriver,
                'dispatch_round' => $order->dispatch_round + 1,
                'dispatch_started_at' => $order->dispatch_started_at ?? now(),
                'dispatch_last_offer_at' => now(),
                // The operator has now seen it; a fresh five-minute clock is noise.
                'dispatch_admin_notified_at' => now(),
            ]);

            $order->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => OrderStatus::SearchingDriver->value,
                'actor_type' => OrderStatusActorType::Admin,
                'actor_id' => $admin->id,
                'reason_code' => $reasonCode,
                'note' => $note,
                'meta' => ['message' => 'تدخّلت الإدارة'],
            ]);

            $group = $order->orderGroup;
            $group->update(['rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status'))]);

            AuditLogger::record($admin, 'orders.reassign_driver', $order, [
                'driver_id' => $driver->id,
                'reason_code' => $reasonCode,
                'mode' => 'offer',
            ]);

            return $order->fresh();
        });

        $this->ring($order, $driver, $admin);

        return $order;
    }

    /**
     * The push and the socket, both outside the transaction.
     *
     * Same rule the dispatch rounds follow: the attempt row **is** the offer, and a
     * captain whose phone is off still finds it on `GET /driver/offers`. Neither of
     * these may fail the assignment.
     */
    private function ring(Order $order, Driver $driver, User $admin): void
    {
        try {
            DriverOfferSent::dispatch((int) $driver->id, (int) $order->id, (int) $order->dispatch_round, null);
        } catch (\Throwable $e) {
            Log::warning('assignment broadcast failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        try {
            $this->notifications->send(
                $driver->user,
                'offer.assigned',
                [
                    'admin' => $admin->name,
                    'order_number' => $order->order_number,
                    'summary' => OfferContext::for($order, $driver)['summary'],
                ],
                'cr7://offer/'.$order->id,
                $order->id,
                ['order_id' => (string) $order->id, 'kind' => 'offer', 'assigned' => '1'],
                channel: 'offers',
            );
        } catch (\Throwable $e) {
            Log::warning('assignment push failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
