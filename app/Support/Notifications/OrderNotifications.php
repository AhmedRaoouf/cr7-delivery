<?php

namespace App\Support\Notifications;

use App\Enums\OrderStatus;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;

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
    /** How long `order.no_driver` stays quiet after it has spoken once for an order. */
    private const NO_DRIVER_REPEAT_MINUTES = 15;

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

        $this->toRestaurant($order, 'order.new', [
            'order_number' => $order->order_number,
            'items' => $order->items()->count(),
            'total' => $this->money($order->total),
        ]);
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
     * doc 04 E-71 — every round is spent and nobody took it.
     *
     * `cr7:tick` retries a stranded order for as long as it is stranded, and each
     * failed retry lands back on this status. Without the cooling-off window below
     * that is one push a minute, all night, to a restaurant and a customer who
     * already know. The retry itself is silent; only the first hour-mark speaks.
     */
    private function noDriver(Order $order): void
    {
        if ($this->sentWithin($order, 'order.no_driver', self::NO_DRIVER_REPEAT_MINUTES)) {
            return;
        }

        $this->toCustomer($order, 'order.no_driver', ['order_number' => $order->order_number]);
        $this->toRestaurant($order, 'order.no_driver_restaurant', ['order_number' => $order->order_number]);
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
        $this->toCustomer($order, 'order.ready');

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

    private function delivered(Order $order): void
    {
        $this->toCustomer($order, 'order.delivered', deepLink: 'cr7://order/'.$order->order_group_id.'/rate');

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

    /** True when this order already produced this notification inside the window. */
    private function sentWithin(Order $order, string $type, int $minutes): bool
    {
        return Notification::query()
            ->where('order_id', $order->id)
            ->where('type', $type)
            ->where('created_at', '>=', now()->subMinutes($minutes))
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
