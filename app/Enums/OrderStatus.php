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

    /**
     * Can the customer still cancel from here, and does it cost them?
     *
     * doc 04 §1 drew one line — the customer keeps the button until the kitchen
     * starts. The operator moved it on 2026-09-09: «الغاء الطلب ينفع طالما
     * المطعم مبدأش تحضير، ولو عايز يلغي بعد كدا لازم يحول رسوم عشرين جنيه». So
     * there are two lines now.
     *
     * Free, because nothing has been cooked: everything up to and including
     * `confirmed`. **`confirmed` belongs here and was missing** — the app has
     * always drawn the button in that state and the server answered
     * `order_not_cancellable`, which is a button that lies.
     *
     * Charged, because a kitchen is working: `preparing`, and only `preparing`.
     * At `ready` the food is packed and a captain is being found, and the
     * customer stops being the only person whose plans changed — from there it
     * is an Admin action exactly as before.
     */
    public function customerCanCancel(): bool
    {
        return $this->customerCancelIsFree() || $this === self::Preparing;
    }

    public function customerCancelIsFree(): bool
    {
        return in_array($this, [
            self::Pending, self::RestaurantTimeout, self::Accepted,
            self::AwaitingCustomerConfirmation, self::AwaitingPayment, self::Confirmed,
        ], true);
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

    /**
     * Static label (doc 04 §5) — the config/enum listing form, without the dynamic
     * {mm:ss}/{code} suffix. Routed through __() so it respects Accept-Language
     * (SetLocaleFromHeader) exactly like every message in the response envelope.
     */
    public function label(): string
    {
        return __($this->arabicLabel());
    }

    private function arabicLabel(): string
    {
        return match ($this) {
            self::Pending, self::RestaurantTimeout => 'بانتظار موافقة المطعم',
            self::Accepted => 'وافق المطعم — بانتظار باقي المطاعم',
            self::AwaitingCustomerConfirmation => 'بانتظار تأكيد الطلب',
            self::AwaitingPayment => 'بانتظار الدفع',
            self::Confirmed => 'تم تأكيد الطلب',
            self::Preparing => 'جاري التحضير',
            self::Ready => 'الطلب جاهز',
            self::SearchingDriver, self::WaitingForDriver => 'جارٍ البحث عن كابتن',
            self::DriverAssigned => 'الكابتن في طريقه إلى المطعم',
            self::ArrivedRestaurant, self::WaitingAtRestaurant => 'وصل الكابتن إلى المطعم',
            self::PickedUp => 'استلم الكابتن الطلب',
            self::OnTheWay => 'الطلب في طريقه إليك',
            self::ArrivedCustomer => 'وصل الكابتن',
            self::Delivered => 'تم التوصيل',
            self::Cancelled => 'تم إلغاء الطلب',
            self::DeliveryIssue => 'توجد مشكلة في الطلب',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::RestaurantTimeout => '#9E9E9E',
            self::Accepted, self::AwaitingCustomerConfirmation, self::AwaitingPayment => '#FFA000',
            self::Confirmed, self::Preparing => '#FFC300',
            self::Ready, self::SearchingDriver, self::WaitingForDriver => '#FF7A00',
            self::DriverAssigned, self::ArrivedRestaurant, self::WaitingAtRestaurant,
            self::PickedUp, self::OnTheWay, self::ArrivedCustomer => '#1976D2',
            self::Delivered => '#2E7D32',
            self::Cancelled => '#C62828',
            self::DeliveryIssue => '#B71C1C',
        };
    }
}
