<?php

namespace App\Support\Catalog;

use App\Enums\OptionPriceMode;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use Illuminate\Support\Collection;

/**
 * doc 78 — the one place that knows what one unit of a dish costs with its choices.
 *
 * The basket, the quote, checkout, the scheduled order, the order snapshot and the
 * «من ‪X‬» on a card each did `(discount_price ?? price) + Σ price_delta` by hand, so a
 * size priced as the whole dish («نص كيلو = 500» on a 950 kilo) was charged 1 450.
 *
 * - An `add` option adds its price.
 * - A `final` option replaces the dish's price with its own. A `final` option priced
 *   0 costs the dish's own price — so «كيلو» beside «نص» and «تلت» needs no number, and
 *   a forgotten price can never make a dish free.
 */
class LinePrice
{
    /** The dish's own price for one unit, before any choice. */
    public static function effective(Product $product): float
    {
        return (float) ($product->discount_price ?? $product->price);
    }

    public static function isFinal(?ProductOptionGroup $group): bool
    {
        return $group !== null && $group->price_mode === OptionPriceMode::Final;
    }

    /** What a `final` option costs one unit (its own price, or the dish's when it has none). */
    public static function finalPrice(ProductOption $option, float $effective): float
    {
        $own = (float) $option->price_delta;

        return $own > 0 ? $own : $effective;
    }

    /**
     * The number printed beside the option: the whole price for a `final` choice, the
     * extra for an `add` one.
     */
    public static function shownPrice(ProductOption $option, ?ProductOptionGroup $group, float $effective): float
    {
        return self::isFinal($group) ? self::finalPrice($option, $effective) : (float) $option->price_delta;
    }

    /**
     * One unit, from the chosen options.
     *
     * `unit` is where the line starts (a chosen `final` option's price, else the dish's),
     * `options_total` the extras on top. `unit + options_total` is one unit.
     *
     * @param  iterable<ProductOption>  $options  each with its `group` loaded (or loadable)
     * @return array{unit: float, options_total: float}
     */
    public static function of(Product $product, iterable $options): array
    {
        $effective = self::effective($product);
        $unit = $effective;
        $extras = 0.0;

        foreach ($options as $option) {
            if ($option === null) {
                continue;
            }

            if (self::isFinal($option->group)) {
                $unit = self::finalPrice($option, $effective);
            } else {
                $extras += (float) $option->price_delta;
            }
        }

        return ['unit' => round($unit, 2), 'options_total' => round($extras, 2)];
    }

    /**
     * A basket line's unit + extras, from its `CartItemOption` rows.
     *
     * @param  Collection<int, mixed>  $itemOptions  rows carrying `productOption`
     * @return array{unit: float, options_total: float}
     */
    public static function ofCartOptions(Product $product, Collection $itemOptions): array
    {
        return self::of($product, $itemOptions->map(fn ($row) => $row->productOption)->filter());
    }

    /**
     * What this option adds on top of the line's `unit` — 0 for a `final` choice, whose
     * price *is* the unit. Old clients sum `unit_price + Σ price_delta`; this keeps them right.
     */
    public static function lineDelta(ProductOption $option): float
    {
        return self::isFinal($option->group) ? 0.0 : (float) $option->price_delta;
    }

    /**
     * On the product sheet, before anything is chosen: what choosing this option changes
     * one unit by, against the dish's own price. A `final` choice cheaper than the dish
     * is negative — which is what an old app, adding deltas to `price`, needs to reach
     * the right total.
     */
    public static function sheetDelta(ProductOption $option, ?ProductOptionGroup $group, float $effective): float
    {
        return self::isFinal($group)
            ? round(self::finalPrice($option, $effective) - $effective, 2)
            : (float) $option->price_delta;
    }
}
