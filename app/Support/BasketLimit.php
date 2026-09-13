<?php

namespace App\Support;

/**
 * D-114 — «الحد الاقصي للعميل انه يطلب من مطعمين بس مينفعش اكتر من كدا وبرده الادمن بيتحكم
 * فيها». Amends D-05's «up to 3».
 *
 * `cart.max_restaurants` (‪1–5‬, default ‪2‬) and `cart.max_qty_per_line` are both the
 * panel's. The limit is read here and nowhere else, so the refusal at «أضف إلى السلة» and
 * the checkout blocker for a basket filled before the limit dropped say the same number
 * in the same sentence.
 */
final class BasketLimit
{
    public static function maxRestaurants(): int
    {
        return max(1, SettingsCatalog::int('cart.max_restaurants'));
    }

    public static function maxQuantityPerLine(): int
    {
        return max(1, SettingsCatalog::int('cart.max_qty_per_line'));
    }

    /** «يمكنك الطلب من مكانين كحد أقصى في الطلب الواحد» — the number in words, as it is said. */
    public static function sentence(?int $max = null): string
    {
        return 'يمكنك الطلب من '.self::places($max ?? self::maxRestaurants()).' كحد أقصى في الطلب الواحد';
    }

    /** «أقصى كمية للصنف الواحد ‪10‬». */
    public static function quantitySentence(?int $max = null): string
    {
        return "أقصى كمية للصنف الواحد \u{202A}".($max ?? self::maxQuantityPerLine())."\u{202C}";
    }

    /**
     * The catalogue caps the setting at five, so words cover every value it can hold. The
     * isolated numeral is for a hand-edited row outside that range.
     */
    private static function places(int $count): string
    {
        return match ($count) {
            1 => 'مكان واحد',
            2 => 'مكانين',
            3 => 'ثلاثة أماكن',
            4 => 'أربعة أماكن',
            5 => 'خمسة أماكن',
            default => "\u{202A}{$count}\u{202C} أماكن",
        };
    }
}
