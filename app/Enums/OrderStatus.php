<?php

namespace App\Enums;

/**
 * The 19-state order machine (doc 04 §1-2). This is the single source of
 * truth for allowed transitions — mirrors the config/order.php map doc 04
 * describes, expressed as behaviour on the enum instead of a bare array.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case RestaurantTimeout = 'restaurant_timeout';
    case Accepted = 'accepted';
    case AwaitingCustomerConfirmation = 'awaiting_customer_confirmation';
    case AwaitingPayment = 'awaiting_payment';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case SearchingDriver = 'searching_driver';
    case WaitingForDriver = 'waiting_for_driver';
    case DriverAssigned = 'driver_assigned';
    case ArrivedRestaurant = 'arrived_restaurant';
    case WaitingAtRestaurant = 'waiting_at_restaurant';
    case PickedUp = 'picked_up';
    case OnTheWay = 'on_the_way';
    case ArrivedCustomer = 'arrived_customer';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case DeliveryIssue = 'delivery_issue';

    /**
     * @return array<self>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Pending => [self::Accepted, self::Cancelled, self::RestaurantTimeout],
            self::RestaurantTimeout => [self::Pending, self::Accepted, self::Cancelled],
            self::Accepted => [self::AwaitingCustomerConfirmation, self::Cancelled],
            self::AwaitingCustomerConfirmation => [self::Confirmed, self::AwaitingPayment, self::Cancelled],
            self::AwaitingPayment => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Preparing, self::Cancelled],
            self::Preparing => [self::Ready, self::Cancelled],
            self::Ready => [self::SearchingDriver, self::Cancelled],
            self::SearchingDriver => [self::DriverAssigned, self::WaitingForDriver, self::Cancelled],
            self::WaitingForDriver => [self::SearchingDriver, self::DriverAssigned, self::Cancelled],
            self::DriverAssigned => [self::ArrivedRestaurant, self::SearchingDriver, self::Cancelled],
            self::ArrivedRestaurant => [self::WaitingAtRestaurant, self::PickedUp, self::SearchingDriver, self::Cancelled],
            self::WaitingAtRestaurant => [self::PickedUp, self::SearchingDriver, self::Cancelled],
            self::PickedUp => [self::OnTheWay, self::DeliveryIssue],
            self::OnTheWay => [self::ArrivedCustomer, self::DeliveryIssue],
            self::ArrivedCustomer => [self::Delivered, self::DeliveryIssue],
            self::DeliveryIssue => [self::Delivered, self::Cancelled],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNextStates(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Delivered || $this === self::Cancelled;
    }

    /** States unreachable by a `restaurant_direct` order (doc 04 §12.1). */
    public static function directOrderUnreachable(): array
    {
        return [
            self::Pending, self::RestaurantTimeout, self::Accepted,
            self::AwaitingCustomerConfirmation, self::AwaitingPayment,
        ];
    }

    /** Customer-visible label key (doc 04 §5) — resolved against translations. */
    public function labelKey(): string
    {
        return "order_status.{$this->value}";
    }
}
