<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Payment;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Notifications\RingService;
use Illuminate\Support\Facades\DB;

/**
 * doc 62 §9 — the clocks `cr7:tick` did not read, and the ring it now keeps.
 *
 * Two deadlines are written on every basket that needs an answer from its customer —
 * `order_groups.confirm_deadline_at` and `payments.deadline_at` — and until now both were
 * checked only when the customer pressed a button. A customer who never came back left
 * the order on the place's board in «بانتظار تأكيد العميل» or «بانتظار التحويل» for ever,
 * and `order.confirm_expired`, `payment.expired` and `payment.due_reminder` had copy and no
 * caller. This closes both flows the way doc 04 §7–§9 always said they close: the basket
 * is cancelled by the system and both sides are told.
 */
final class OrderDeadlines
{
    /** How old an order may be and still be spoken for — anything older is abandoned data. */
    private const MAX_AGE_HOURS = 24;

    /** D-100 — rings two and three. Ring one is sent when the order is committed. */
    public static function ringAgain(): int
    {
        if (! RingService::enabled()) {
            return 0;
        }

        $max = SettingsCatalog::int('ring.max_attempts');

        $orders = Order::query()
            ->where('status', OrderStatus::Pending)
            ->where('origin', '!=', 'restaurant_direct')
            ->whereNotNull('ring_next_at')
            ->where('ring_next_at', '<=', now())
            ->where('ring_attempts', '<', $max)
            ->where('created_at', '>=', now()->subHours(self::MAX_AGE_HOURS))
            ->with(['restaurant.owner', 'items'])
            ->limit(50)
            ->get();

        $ring = app(RingService::class);

        foreach ($orders as $order) {
            $ring->ring($order, (int) $order->ring_attempts + 1);
        }

        return $orders->count();
    }

    /** The customer never confirmed: `customer_did_not_confirm`, doc 04 §8. */
    public static function expireConfirmations(): int
    {
        $groups = OrderGroup::query()
            ->whereNull('confirmed_at')
            ->whereNotNull('confirm_deadline_at')
            ->where('confirm_deadline_at', '<=', now())
            ->where('placed_at', '>=', now()->subHours(self::MAX_AGE_HOURS))
            ->whereHas('orders', fn ($q) => $q->where('status', OrderStatus::AwaitingCustomerConfirmation))
            ->limit(50)
            ->get();

        foreach ($groups as $group) {
            self::cancel($group, OrderStatus::AwaitingCustomerConfirmation, 'customer_did_not_confirm');
        }

        return $groups->count();
    }

    /** T − `payment.reminder_before_minutes`: one reminder, while there is still time. */
    public static function remindPayments(): int
    {
        $before = SettingsCatalog::int('payment.reminder_before_minutes');

        $payments = Payment::query()
            ->where('status', 'awaiting_proof')
            ->where('reminder_sent', false)
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '>', now())
            ->where('deadline_at', '<=', now()->addMinutes($before))
            ->with('orderGroup.user')
            ->limit(50)
            ->get();

        foreach ($payments as $payment) {
            $group = $payment->orderGroup;
            $payment->forceFill(['reminder_sent' => true])->save();

            if ($group?->user === null) {
                continue;
            }

            app(NotificationDispatcher::class)->send(
                $group->user,
                'payment.due_reminder',
                [
                    'minutes' => max(1, (int) ceil(now()->diffInSeconds($payment->deadline_at) / 60)),
                    'order_number' => $group->group_number,
                ],
                'cr7://order/'.$group->id.'/pay',
                $group->orders()->value('id'),
                ['group_id' => (string) $group->id, 'order_id' => (string) $group->orders()->value('id')],
            );
        }

        return $payments->count();
    }

    /**
     * The transfer never came: `payment_not_received`. A receipt already under review is
     * the office's to decide, not the clock's — it is left alone.
     */
    public static function expirePayments(): int
    {
        $payments = Payment::query()
            ->where('status', 'awaiting_proof')
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', now())
            ->where('created_at', '>=', now()->subHours(self::MAX_AGE_HOURS))
            ->with('orderGroup')
            ->limit(50)
            ->get();

        foreach ($payments as $payment) {
            if ($payment->orderGroup === null) {
                continue;
            }

            DB::transaction(function () use ($payment) {
                $payment->update(['status' => 'failed', 'failure_reason' => 'deadline_passed']);
                self::cancel($payment->orderGroup, OrderStatus::AwaitingPayment, 'payment_not_received');
            });
        }

        return $payments->count();
    }

    private static function cancel(OrderGroup $group, OrderStatus $from, string $reason): void
    {
        DB::transaction(function () use ($group, $from, $reason) {
            $orders = $group->orders()->where('status', $from)->get();

            foreach ($orders as $order) {
                $order->update([
                    'status' => OrderStatus::Cancelled,
                    'cancelled_by' => 'system',
                    'cancellation_reason_code' => $reason,
                    'cancelled_at' => now(),
                ]);

                $order->statusHistory()->create([
                    'from_status' => $from->value,
                    'to_status' => OrderStatus::Cancelled->value,
                    'actor_type' => OrderStatusActorType::System,
                    'reason_code' => $reason,
                ]);
            }

            $statuses = $group->orders()->get()->pluck('status');

            $group->update([
                'rollup_status' => RollupStatus::compute($statuses),
                'completed_at' => $statuses->every(fn ($s) => in_array($s->value, ['delivered', 'cancelled'], true)) ? now() : null,
            ]);
        });
    }
}
