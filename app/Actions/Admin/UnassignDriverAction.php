<?php

namespace App\Actions\Admin;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\DispatchService;
use App\Support\Otp;
use App\Support\RollupStatus;
use Illuminate\Support\Facades\DB;

/** doc 24 §5 / doc 16 D-54 — remove the captain without picking a replacement. */
class UnassignDriverAction
{
    private const BLOCKED_FROM = ['picked_up', 'on_the_way', 'arrived_customer', 'delivered', 'cancelled'];

    public function __construct(private readonly DispatchService $dispatch)
    {
    }

    public function execute(User $admin, Order $order, string $reasonCode, ?string $note, bool $requeue): Order
    {
        if (in_array($order->status->value, self::BLOCKED_FROM, true)) {
            throw new ApiException('invalid_transition', 'لا يمكن إزالة الكابتن بعد استلام الطلب', 422);
        }

        if (! $order->driver_id) {
            throw new ApiException('invalid_transition', 'لا يوجد كابتن معيّن على هذا الطلب', 422);
        }

        $round = $order->dispatch_round;

        DB::transaction(function () use ($admin, $order, $reasonCode, $note, $requeue) {
            $driver = $order->driver;

            $order->driverAttempts()->where('driver_id', $driver->id)->where('result', 'accepted')
                ->update(['result' => 'unassigned_by_admin', 'reason_code' => $reasonCode, 'responded_at' => now()]);

            $driver->update(['is_available' => true, 'active_order_id' => null]);

            $from = $order->status;
            $nextStatus = $requeue ? OrderStatus::SearchingDriver : OrderStatus::Ready;

            $order->update([
                'driver_id' => null,
                'status' => $nextStatus,
                'pickup_otp' => Otp::orderCode(),
                'pickup_otp_attempts' => 0,
            ]);

            $order->statusHistory()->create([
                'from_status' => $from->value,
                'to_status' => $nextStatus->value,
                'actor_type' => OrderStatusActorType::Admin,
                'actor_id' => $admin->id,
                'reason_code' => $reasonCode,
                'note' => $note,
                'meta' => ['message' => 'تدخّلت الإدارة'],
            ]);

            $group = $order->orderGroup;
            $group->update(['rollup_status' => RollupStatus::compute($group->orders()->get()->pluck('status'))]);

            AuditLogger::record($admin, 'orders.unassign_driver', $order, ['reason_code' => $reasonCode, 'requeue' => $requeue]);
        });

        if ($requeue) {
            $this->dispatch->dispatchRound($order->fresh(), $round + 1);
        }

        return $order->fresh();
    }
}
