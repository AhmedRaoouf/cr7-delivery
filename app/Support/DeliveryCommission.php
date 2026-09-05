<?php

namespace App\Support;

/**
 * D-70 — the platform's share of a delivery fee, and the captain's.
 *
 * Operator, 2026-09-05: «سعر الديليفري ده … الأدمين بياخد منه 15% والباقي بيروح
 * للديليفري». One rate, one place, so the two halves can never add up to anything
 * but the fee itself.
 *
 * The rate is a **setting**, not a constant: `delivery.platform_commission_rate`.
 * It is deliberately not per-driver — a captain's `earning_type` may still be
 * `fixed` or `per_km` where the operator has set one, and {@see self::isStandard()}
 * is how the rest of the code asks whether a captain is on the standard split.
 *
 * Nothing here is charged to the customer. The fee the flyer prints is the fee the
 * customer pays; this only decides who keeps which part of it.
 */
class DeliveryCommission
{
    public const SETTING = 'delivery.platform_commission_rate';

    /** The platform's percentage of the delivery fee. */
    public static function rate(): float
    {
        return max(0.0, min(100.0, (float) Settings::get(self::SETTING, 15)));
    }

    /** The captain's percentage — the remainder, so the two always close. */
    public static function driverPercent(): float
    {
        return round(100 - self::rate(), 2);
    }

    /** What the platform keeps out of `$fee`. */
    public static function platformShare(float $fee): float
    {
        return round($fee * self::rate() / 100, 2);
    }

    /**
     * What the captain earns out of `$fee`.
     *
     * Subtraction, not a second multiplication: on a 25.00 fee at 15 % the two
     * roundings would otherwise leave a stray قرش that shows up as a settlement
     * that does not balance.
     */
    public static function driverShare(float $fee): float
    {
        return round($fee - self::platformShare($fee), 2);
    }
}
