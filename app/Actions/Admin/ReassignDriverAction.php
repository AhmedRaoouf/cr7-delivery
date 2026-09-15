<?php

namespace App\Actions\Admin;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\DriverTrip;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Otp;
use App\Support\TripAssignment;
use Illuminate\Support\Facades\DB;

/**
 * doc 24 §5 / doc 16 D-54 — hand the order to a named captain. Closes the old
 * attempt row with an admin result code, regenerates the pickup OTP, and never
 * exists once the order has been picked_up.
 *
 * doc 63 §3.6 — the captain no longer has to be empty-handed, only to have room: the
 * target must pass the capacity gate (`driver_trip_full`) and must not be on an errand
 * (`driver_on_an_errand`). How far this order's place is from the rest of their trip is
 * the operator's call and is not checked.
 */
class ReassignDriverAction
{
    private const BLOCKED_FROM = ['picked_up', 'on_the_way', 'arrived_customer', 'delivered', 'cancelled'];

    public function execute(User $admin, Order $order, int $driverId, string $reasonCode, ?string $note): Order
    {
        if (in_array($order->status->value, self::BLOCKED_FROM, true)) {
            throw new ApiException('invalid_transition', 'لا يمكن تغيير الكابتن بعد استلام الطلب', 422);
        }

        $newDriver = Driver::query()->where('is_active', true)->findOrFail($driverId);

        self::assertTheCaptainCanTakeIt($newDriver, $order);

        return DB::transaction(function () use ($admin, $order, $newDriver, $reasonCode, $note) {
            $oldDriver = $order->driver;
            $from = $order->status;

            // The captain who loses it can never be its holder again (doc 63 §1.2). Their
            // trip is re-derived by `OrderObserver` on the assignment below.
            if ($oldDriver) {
                $order->driverAttempts()->where('driver_id', $oldDriver->id)->where('result', 'accepted')
                    ->update(['result' => 'reassigned_by_admin', 'reason_code' => $reasonCode, 'responded_at' => now()]);
            }

            /*
             * 🔴 The money was never recalculated here, and the new captain inherited the
             * old one's numbers. `TripAssignment` snapshots `driver_earning` and
             * `platform_delivery_margin` exactly as an accept does (doc 62 §4.6).
             */
            TripAssignment::assign(
                $order,
                $newDriver,
                OrderStatusActorType::Admin,
                $admin->id,
                TripAssignment::VIA_OFFICE,
                history: ['reason_code' => $reasonCode, 'note' => $note, 'meta' => ['message' => 'تدخّلت الإدارة']],
                columns: ['pickup_otp' => Otp::orderCode(), 'pickup_otp_attempts' => 0],
                round: (int) $order->dispatch_round + 1,
            );

            AuditLogger::record($admin, 'orders.reassign_driver', $order, ['driver_id' => $newDriver->id, 'reason_code' => $reasonCode]);

            // The captain who lost it is told. `OrderNotifications::driverChanged()` covers
            // a reassignment that leaves the status where it was (`driver_assigned` stays
            // `driver_assigned`); one made after the old captain reached the counter moves
            // it back to `driver_assigned`, and that captain heard nothing.
            if ($oldDriver !== null && $from !== OrderStatus::DriverAssigned && $oldDriver->user !== null) {
                app(NotificationDispatcher::class)->send(
                    $oldDriver->user,
                    'order.reassigned',
                    ['order_number' => $order->order_number],
                    null,
                    $order->id,
                    ['order_id' => (string) $order->id],
                );
            }

            return $order->fresh();
        });
    }

    /**
     * The office's two refusals (doc 63 §2.4) — shared with the assignment that arrives as
     * an offer, {@see OfferOrderToDriverAction}.
     */
    public static function assertTheCaptainCanTakeIt(Driver $driver, Order $order): void
    {
        if (DriverTrip::onErrand($driver)) {
            throw new ApiException('driver_on_an_errand', 'هذا الكابتن في مشوار الآن', 422);
        }

        if (DriverTrip::capacityRefusal($driver, $order) !== null) {
            throw new ApiException('driver_trip_full', 'رحلة هذا الكابتن مكتملة', 422, meta: [
                'trip_count' => DriverTrip::count($driver),
                'trip_max' => DriverTrip::max(),
            ]);
        }
    }
}
