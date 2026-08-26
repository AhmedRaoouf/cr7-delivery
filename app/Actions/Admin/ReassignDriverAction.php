<?php

namespace App\Actions\Admin;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\RollupStatus;
use Illuminate\Support\Facades\DB;

/**
 * doc 24 §5 / doc 16 D-54 — hand the order to a named captain. Closes the old
 * attempt row with an admin result code, regenerates the pickup OTP, and never
 * exists once the order has been picked_up.
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

        if ($newDriver->active_order_id) {
            throw new ApiException('driver_already_busy', 'هذا الكابتن مشغول بطلب آخر حاليًا', 422);
        }

        return DB::transaction(function () use ($admin, $order, $newDriver, $reasonCode, $note) {
            $oldDriver = $order->driver;

            if ($oldDriver) {
                $order->driverAttempts()->where('driver_id', $oldDriver->id)->where('result', 'accepted')
                    ->update(['result' => 'reassigned_by_admin', 'reason_code' => $reasonCode, 'responded_at' => now()]);
                $oldDriver->update(['is_available' => true, 'active_order_id' => null]);
            }

            $order->driverAttempts()->create([
                'driver_id' => $newDriver->id,
                'round' => $order->dispatch_round + 1,
                'distance_km' => 0,
                'result' => 'accepted',
                'sent_at' => now(),
                'responded_at' => now(),
            ]);

            $from = $order->status;

            $order->update([
                'driver_id' => $newDriver->id,
                'status' => OrderStatus::DriverAssigned,
                'driver_assigned_at' => now(),
                'pickup_otp' => (string) random_int(1000, 9999),
                'pickup_otp_attempts' => 0,
            ]);

            $newDriver->update(['is_available' => false, 'active_order_id' => $order->id]);

            $order->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => OrderStatus::DriverAssigned->value,
                'actor_type' => OrderStatusActorType::Admin,
                'actor_id' => $admin->id,
                'reason_code' => $reasonCode,
                'note' => $note,
                'meta' => ['message' => 'تدخّلت الإدارة'],
            ]);

            $group = $order->orderGroup;
            $group->update(['rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status'))]);

            AuditLogger::record($admin, 'orders.reassign_driver', $order, ['driver_id' => $newDriver->id, 'reason_code' => $reasonCode]);

            return $order->fresh();
        });
    }
}
