<?php

namespace App\Actions\Admin;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\DispatchService;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Otp;
use App\Support\RollupStatus;
use Illuminate\Support\Facades\DB;

/** doc 24 §5 / doc 16 D-54 — remove the captain without picking a replacement. */
class UnassignDriverAction
{
    private const BLOCKED_FROM = ['picked_up', 'on_the_way', 'arrived_customer', 'delivered', 'cancelled'];

    public function __construct(private readonly DispatchService $dispatch) {}

    public function execute(User $admin, Order $order, string $reasonCode, ?string $note, bool $requeue): Order
    {
        if (in_array($order->status->value, self::BLOCKED_FROM, true)) {
            throw new ApiException('invalid_transition', 'لا يمكن إزالة الكابتن بعد استلام الطلب', 422);
        }

        if (! $order->driver_id) {
            throw new ApiException('invalid_transition', 'لا يوجد كابتن معيّن على هذا الطلب', 422);
        }

        $round = $order->dispatch_round;
        $removed = $order->driver?->user;

        DB::transaction(function () use ($admin, $order, $reasonCode, $note, $requeue) {
            $driver = $order->driver;

            // Marked, so the holder rule of doc 63 §1.2 never hands this order back to the
            // captain it was just taken from — the requeue below would otherwise do exactly
            // that when they still carry a sibling. Their trip is re-derived by
            // `OrderObserver` when `driver_id` is cleared.
            $order->driverAttempts()->where('driver_id', $driver->id)->where('result', 'accepted')
                ->update(['result' => 'unassigned_by_admin', 'reason_code' => $reasonCode, 'responded_at' => now()]);

            $from = $order->status;
            $nextStatus = $requeue ? OrderStatus::SearchingDriver : OrderStatus::Ready;

            $order->update([
                'driver_id' => null,
                'status' => $nextStatus,
                'pickup_otp' => Otp::orderCode(),
                'pickup_otp_attempts' => 0,
                // doc 62 §4.6 — the removed captain's numbers leave with him; the next
                // captain's are written when he accepts.
                'driver_earning' => 0,
                'platform_delivery_margin' => 0,
                // Whichever way it goes, this order is now waiting for a captain again —
                // and the two-minute escalation has to be able to speak a second time.
                // Without a requeue it sits on `ready` with nobody searching, which is
                // exactly the state the operator most needs the dock to shout about.
                'dispatch_started_at' => now(),
                'dispatch_admin_notified_at' => null,
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

        // The captain it was taken from is told. `OrderNotifications::driverChanged()` says
        // so only when `driver_id` moves **without** a status change, and an unassign always
        // moves the status too — so the captain learned it at the counter, if at all.
        if ($removed !== null) {
            app(NotificationDispatcher::class)->send(
                $removed,
                'order.unassigned',
                ['order_number' => $order->order_number],
                null,
                $order->id,
                ['order_id' => (string) $order->id, 'reason_code' => $reasonCode],
            );
        }

        // A retry, not a first round: a first round excludes every captain ever asked about
        // this order, so the requeue reached nobody until the two-minute rebroadcast. A
        // retry excludes only those who refused it or were taken off it.
        if ($requeue) {
            $this->dispatch->dispatchRound($order->fresh(), $round + 1, retry: true);
        }

        return $order->fresh();
    }
}
