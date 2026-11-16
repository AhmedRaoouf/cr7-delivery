<?php

namespace App\Support;

/**
 * doc 107 — «توصيل مجاني من» is an offer only when it names a positive amount.
 *
 * Empty and `0` both mean **no offer**. The web zone form used to start the field at
 * `0` and save it on every edit, and `$subtotal >= 0` is true for every basket, so
 * raising the area fees on 2026-11-16 made delivery free in every area for two hours.
 * A zone that should deliver for nothing sets its `delivery_fee` to 0 instead.
 */
final class FreeDeliveryThreshold
{
    public static function of(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $amount = round((float) $value, 2);

        return $amount > 0 ? $amount : null;
    }
}
