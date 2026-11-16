<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * doc 107 §5 — the place's own order number is unique per place per business day
 * (`orders_restaurant_ref_business_date_unique`). Places type short numbers («1»,
 * «12», «123») and repeat them, and a repeat used to reach the database and answer
 * 500 — «بدء التحضير» failed with a generic error, 16–92 log lines a day since
 * 2026-11-09. A repeat is now refused with the number named, before the write.
 */
final class RestaurantOrderRef
{
    public static function takenBy(Order $order, ?string $ref): ?Order
    {
        if ($ref === null || trim($ref) === '' || $ref === $order->restaurant_order_ref) {
            return null;
        }

        return Order::query()
            ->where('restaurant_id', $order->restaurant_id)
            ->whereDate('business_date', $order->business_date ?? BusinessTime::date())
            ->where('restaurant_order_ref', $ref)
            ->whereKeyNot($order->getKey())
            ->first(['id', 'order_number']);
    }

    /** Refuse a number another order of this place already carries today. */
    public static function assertFree(Order $order, ?string $ref): void
    {
        if (self::takenBy($order, $ref) !== null) {
            throw self::taken((string) $ref);
        }
    }

    public static function taken(string $ref): ApiException
    {
        return new ApiException(
            'duplicate_restaurant_order_ref',
            "رقم الطلب \u{202A}{$ref}\u{202C} مستخدم لطلب آخر اليوم. اكتب رقم الطلب كما هو في نظام المطعم.",
            422,
        );
    }

    /**
     * Run a write that sets the number; a race the pre-check could not see still
     * answers the same 422, never 500.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    public static function guard(?string $ref, callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'orders_restaurant_ref_business_date_unique')) {
                throw $e;
            }

            throw self::taken((string) $ref);
        }
    }
}
