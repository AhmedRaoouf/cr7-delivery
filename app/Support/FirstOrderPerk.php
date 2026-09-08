<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;

/**
 * «خلي اول اورد للحساب توصيل مجاني» — operator, 2026-09-08.
 *
 * The platform's own welcome offer: the first order an account ever completes
 * carries no delivery fee. It is not a promo code — there is nothing to type,
 * nothing to remember, and nothing to enter wrong — so it applies in
 * `CheckoutPricer` beside the zone's own `free_delivery_over` rather than in
 * the promo branch.
 *
 * Switched from Admin like everything else («ظبط التحكم من الادمين في كل
 * حاجه»): `delivery.first_order_free`. Off, this class answers false to
 * everything and no screen mentions it.
 *
 * **What counts as having ordered before.** A cancelled order does not burn the
 * offer: an account whose first attempt was refused by a shop, or cancelled
 * while it was still free to cancel, has not had a delivery. Anything that
 * reached a shop's kitchen has.
 */
class FirstOrderPerk
{
    public const string SETTING = 'delivery.first_order_free';

    /**
     * Statuses that mean this account has already been served, or is being
     * served right now. A second order placed while the first is still on the
     * road is not a first order.
     */
    private const array SPENT = [
        OrderStatus::Confirmed,
        OrderStatus::Preparing,
        OrderStatus::Ready,
        OrderStatus::SearchingDriver,
        OrderStatus::WaitingForDriver,
        OrderStatus::DriverAssigned,
        OrderStatus::ArrivedRestaurant,
        OrderStatus::WaitingAtRestaurant,
        OrderStatus::PickedUp,
        OrderStatus::OnTheWay,
        OrderStatus::ArrivedCustomer,
        OrderStatus::Delivered,
        OrderStatus::DeliveryIssue,
    ];

    public static function isEnabled(): bool
    {
        return (bool) Settings::get(self::SETTING, true);
    }

    /** Whether this account's next order is its first, and the offer is on. */
    public static function appliesTo(?User $user): bool
    {
        if ($user === null || ! self::isEnabled()) {
            return false;
        }

        return ! Order::query()
            ->where('user_id', $user->id)
            ->whereIn('status', array_map(static fn (OrderStatus $s) => $s->value, self::SPENT))
            ->exists();
    }
}
