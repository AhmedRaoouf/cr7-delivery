<?php

namespace App\Support\Notifications;

use App\Enums\OrderStatus;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Which notifications a status change owes, and to whom.
 *
 * doc 11 lists the triggers as prose against each row; this is the same list as
 * a `match`, driven from the transition `OrderObserver` already sees. Keeping it
 * beside the observer rather than scattering `notify()` calls through the
 * nineteen places that write `status` is the same argument the observer's own
 * docblock makes: a call site that is easy to forget is one that will be
 * forgotten, and the failure is silent.
 *
 * The status is read from the model, so a transition that never happened cannot
 * produce a notification claiming it did.
 */
class OrderNotifications
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(Order $order, ?string $previousStatus): void
    {
        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::tryFrom((string) $order->status);

        if ($status === null) {
            return;
        }

        // A new row is the order arriving, not a transition into `pending`.
        if ($previousStatus === null) {
            $this->placed($order);

            return;
        }

        if ($previousStatus === $status->value) {
            // `driver_id` changed without a status change — the captain was set,
            // reassigned or removed. doc 24 §5.3/§5.4.
            $this->driverChanged($order);

            return;
        }

        match ($status) {
            OrderStatus::RestaurantTimeout => $this->restaurantTimeout($order),
            OrderStatus::AwaitingCustomerConfirmation => $this->awaitingConfirmation($order),
            OrderStatus::AwaitingPayment => $this->awaitingPayment($order),
            OrderStatus::Confirmed => $this->confirmed($order),
            OrderStatus::Preparing => $this->preparing($order),
            OrderStatus::Ready => $this->ready($order),
            OrderStatus::WaitingForDriver => $this->noDriver($order),
            OrderStatus::DriverAssigned => $this->driverAssigned($order),
            OrderStatus::ArrivedRestaurant => $this->driverAtRestaurant($order),
            OrderStatus::PickedUp => $this->pickedUp($order),
            OrderStatus::ArrivedCustomer => $this->arrived($order),
            OrderStatus::Delivered => $this->delivered($order),
            OrderStatus::Cancelled => $this->cancelled($order),
            OrderStatus::DeliveryIssue => $this->issue($order),
            default => null,
        };
    }

    // ------------------------------------------------------------ transitions

    private function placed(Order $order): void
    {
        // D-47 — a direct order was raised *by* the restaurant for a customer the
        // app never saw. There is nobody to tell it was placed, and telling the
        // restaurant about its own action is noise.
        if ($order->isDirect()) {
            return;
        }

        $this->toCustomer($order, 'order.placed', [
            'order_number' => $order->order_number,
        ]);

        /*
         * 🔴 Two defects in one line, both invisible in production.
         *
         * `OrderObserver::created` fires inside `Order::create`, which `CreateOrderAction`
         * runs **before** it writes the order's items — so `items()->count()` was 0 and
         * every kitchen in Gamasa has been receiving «‪0‬ أصناف» since the push existed.
         * Counting after the commit is the fix, and `DB::afterCommit` is where the rows
         * are guaranteed to be there (it runs immediately when no transaction is open,
         * which is the direct-order path this method already returns from).
         *
         * And the figure was `total` — the sub-order's share of the delivery fee and the
         * service fee included. Operator, 2026-09-11: «الـ notification تبع قبول الطلب
         * يظهر عدد الأصناف وحساب المطعم فقط وليس الـ total». What the place is owed for
         * its own food is `subtotal − commission`, which is what its settlement pays it.
         */
        DB::afterCommit(function () use ($order): void {
            $fresh = $order->fresh(['items']);

            if ($fresh === null) {
                return;
            }

            $this->toRestaurant($fresh, 'order.new', [
                'order_number' => $fresh->order_number,
                'items' => $fresh->items_count,
                'total' => $this->money($fresh->restaurant_amount),
            ]);
        });
    }

    /**
     * The accept window ran out. Both sides are told, and neither is told the
     * order is dead — `restaurant_timeout` is still a live order the restaurant
     * can accept (OrderStatus::Pending's transition list allows the way back).
     */
    private function restaurantTimeout(Order $order): void
    {
        $this->toCustomer($order, 'order.timeout', [
            'order_number' => $order->order_number,
            'restaurant' => $this->restaurantName($order),
        ]);

        $this->toRestaurant($order, 'order.timeout_restaurant', [
            'order_number' => $order->order_number,
        ]);
    }

    /**
     * D-38 — the transfer window opens here, and `payment.due` has existed in
     * `NotificationCopy` since it was written without a single caller. A customer
     * who put the phone down after checkout learned about the deadline by
     * missing it.
     */
    private function awaitingPayment(Order $order): void
    {
        if (! $this->isLastToAnswer($order)) {
            return;
        }

        $this->toCustomer($order, 'payment.due', [
            'amount' => $this->money($order->orderGroup?->total ?? $order->total),
        ], deepLink: 'cr7://order/'.$order->order_group_id.'/pay');
    }

    /**
     * `searching_driver → waiting_for_driver` now tells **nobody**.
     *
     * Operator, 2026-09-11: «اشعارات العميل تكون تفاصيل طلبه فقط — إنما حاجة زي الديليفري
     * رفض الطلب دي متروحش للعميل، تروح للأدمن». This status is not a fact about the
     * customer's order; it is a fact about the dispatcher's last pass, and it flipped on
     * a single captain pressing «رفض» — «لم نجد كابتنًا متاحًا لطلبك» is what the customer
     * read, fifteen minutes apart, while the search was still running.
     *
     * The place is not told here either, because this status can be reached in the same
     * second the food was marked ready — «لم يقبل أي كابتن» one second in is wrong. Both
     * the place and the operators hear at the two-minute mark instead, from
     * `TickCommand::escalateUnclaimed()`, which is the one place that knows how long it
     * has actually been.
     */
    private function noDriver(Order $order): void
    {
        // Intentionally silent — see the docblock. Kept as an arm of the match so the
        // next person to add a notification here reads why there is none.
    }

    /** The kitchen is not always looking at the board, and the handover starts now. */
    private function driverAtRestaurant(Order $order): void
    {
        $this->toRestaurant($order, 'order.driver_at_restaurant', [
            'order_number' => $order->order_number,
            'driver' => $order->driver?->user?->name,
        ]);
    }

    private function awaitingConfirmation(Order $order): void
    {
        // D-36/D-37 — the gate is the *group's*, so the customer is told once by
        // whichever sub-order closes it, not once per restaurant.
        if ($this->isLastToAnswer($order)) {
            $this->toCustomer($order, 'order.confirm_required', [
                'amount' => $this->money($order->orderGroup?->total ?? $order->total),
            ], deepLink: 'cr7://order/'.$order->order_group_id.'/confirm');
        }

        $this->toRestaurant($order, 'order.awaiting_customer_confirmation', [
            'order_number' => $order->order_number,
        ]);
    }

    private function confirmed(Order $order): void
    {
        $this->toCustomer($order, 'order.confirmed', [
            'restaurant' => $this->restaurantName($order),
        ]);

        $this->toRestaurant($order, 'order.confirmed_restaurant', [
            'order_number' => $order->order_number,
        ]);
    }

    private function preparing(Order $order): void
    {
        $this->toCustomer($order, 'order.preparing', [
            'prep_minutes' => $order->prep_minutes,
        ]);
    }

    private function ready(Order $order): void
    {
        // Said once. `UnassignDriverAction` without a requeue puts the order back on
        // `ready`, and a customer whose captain was swapped by an operator was told
        // «طلبك جاهز» a second time as though nothing had happened in between.
        if (! $this->alreadySent($order, 'order.ready')) {
            $this->toCustomer($order, 'order.ready', ['restaurant' => $this->restaurantName($order)]);
        }

        // doc 04 §10.4 — the timer, not the kitchen, moved this one. That is the
        // whole point of the countdown, and the restaurant is owed the fact.
        // `order.prep_timer_expired` had no caller before `cr7:tick` could fire it.
        if (($order->ready_source?->value ?? $order->ready_source) === 'timer') {
            $this->toRestaurant($order, 'order.prep_timer_expired', [
                'order_number' => $order->order_number,
            ]);
        }

        // The captain waiting at the counter is the one person for whom «ready»
        // is an instruction rather than a status.
        if ($order->driver?->user !== null) {
            $this->dispatcher->send(
                $order->driver->user,
                'order.ready_for_pickup',
                ['restaurant_order_ref' => $order->restaurant_order_ref],
                'cr7://order/'.$order->id,
                $order->id,
                ['order_id' => (string) $order->id],
            );
        }
    }

    private function driverAssigned(Order $order): void
    {
        $this->toCustomer($order, 'order.driver_assigned', [
            'driver' => $order->driver?->user?->name,
            'restaurant' => $this->restaurantName($order),
        ]);

        $this->toDriver($order, 'order.assigned', [
            'restaurant_order_ref' => $order->restaurant_order_ref,
        ]);
    }

    private function pickedUp(Order $order): void
    {
        // D-43 — the delivery code is issued at pickup and this push is where the
        // customer meets it. Isolate::ltrIfNumeric is what keeps its four digits
        // in the order they were generated.
        $this->toCustomer($order, 'order.picked_up', [
            'delivery_otp' => $order->delivery_otp,
        ]);
    }

    private function arrived(Order $order): void
    {
        $this->toCustomer($order, 'order.arrived', [
            'delivery_otp' => $order->delivery_otp,
        ]);
    }

    /**
     * The customer hears «تم التوصيل» **once per basket**, because there is one rating
     * per basket (D-80). A three-restaurant order used to send three, each deep-linking
     * to the same rating screen, two of which would answer «تم تقييم هذا الطلب من قبل».
     * The place still hears about its own sub-order.
     */
    private function delivered(Order $order): void
    {
        if ($this->isLastLiveInGroup($order)) {
            $this->toCustomer($order, 'order.delivered', deepLink: 'cr7://order/'.$order->order_group_id.'/rate');
        }

        $this->toRestaurant($order, 'order.delivered_restaurant', [
            'order_number' => $order->order_number,
        ]);
    }

    private function cancelled(Order $order): void
    {
        $this->toCustomer($order, 'order.cancelled', ['order_number' => $order->order_number]);
        $this->toRestaurant($order, 'order.cancelled_restaurant', ['order_number' => $order->order_number]);
    }

    private function issue(Order $order): void
    {
        $this->toCustomer($order, 'order.issue');
    }

    private function driverChanged(Order $order): void
    {
        $previous = $order->getOriginal('driver_id');

        // The captain who lost it. D-54: `reassign` and `unassign` differ only in
        // whether somebody took their place, and the captain is owed the right
        // one of the two.
        if ($previous !== null) {
            $lost = User::query()
                ->whereHas('driver', fn ($q) => $q->whereKey($previous))
                ->first();

            $this->dispatcher->send(
                $lost,
                $order->driver_id === null ? 'order.unassigned' : 'order.reassigned',
                ['order_number' => $order->order_number],
                null,
                $order->id,
                ['order_id' => (string) $order->id],
            );
        }

        if ($order->driver_id !== null && $previous !== null) {
            $this->toDriver($order, 'order.assigned', [
                'restaurant_order_ref' => $order->restaurant_order_ref,
            ]);
        }
    }

    // --------------------------------------------------------------- plumbing

    /**
     * True when every sub-order in the group has answered — which is the moment
     * doc 04 §7.2 calls «the gate opened». A single-restaurant order is trivially
     * the last to answer.
     */
    private function isLastToAnswer(Order $order): bool
    {
        $group = $order->orderGroup;
        if ($group === null) {
            return true;
        }

        return ! $group->orders()
            ->whereKeyNot($order->id)
            ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Accepted->value])
            ->exists();
    }

    /** @param array<string, string|int|float|null> $context */
    private function toCustomer(Order $order, string $type, array $context = [], ?string $deepLink = null): void
    {
        $this->dispatcher->send(
            $order->user,
            $type,
            $context,
            $deepLink ?? 'cr7://order/'.$order->order_group_id,
            $order->id,
            ['order_id' => (string) $order->id, 'group_id' => (string) $order->order_group_id],
        );
    }

    /** @param array<string, string|int|float|null> $context */
    private function toRestaurant(Order $order, string $type, array $context = []): void
    {
        $this->dispatcher->send(
            $order->restaurant?->owner,
            $type,
            $context,
            '/orders/'.$order->id,
            $order->id,
            ['order_id' => (string) $order->id],
        );
    }

    /** @param array<string, string|int|float|null> $context */
    private function toDriver(Order $order, string $type, array $context = []): void
    {
        $this->dispatcher->send(
            $order->driver?->user,
            $type,
            $context,
            'cr7://order/'.$order->id,
            $order->id,
            ['order_id' => (string) $order->id],
        );
    }

    /** True when this order has ever produced this notification. */
    private function alreadySent(Order $order, string $type): bool
    {
        return Notification::query()
            ->where('order_id', $order->id)
            ->where('type', $type)
            ->exists();
    }

    /**
     * True when every other sub-order in the basket has already finished — so this one
     * ending is the basket ending.
     */
    private function isLastLiveInGroup(Order $order): bool
    {
        $group = $order->orderGroup;

        if ($group === null) {
            return true;
        }

        return ! $group->orders()
            ->whereKeyNot($order->id)
            ->whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value])
            ->exists();
    }

    private function restaurantName(Order $order): ?string
    {
        return $order->restaurant?->name_ar;
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2).' ج.م';
    }
}
