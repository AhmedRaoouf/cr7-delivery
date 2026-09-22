<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Restaurant;
use App\Support\Catalog\OptionSelection;

/**
 * D-60 — `restaurants.price_band` is derived from the menu, never declared by hand.
 *
 * It is the **median base price** of the restaurant's own available rows, bucketed at
 * the two cuts below. A hand-set band is a number somebody has to remember to update;
 * a median is the menu telling the truth about itself. A restaurant with no published
 * menu carries **null** and answers no chip — «متوسط» on an unpriced brand would be a
 * lie told inside a price filter.
 */
class PriceBand
{
    /** ج.م — the cuts fall between doc 17's clusters, so no chip is a dead end. */
    public const BUDGET_CEILING = 75.0;

    public const MID_CEILING = 130.0;

    public static function forRestaurant(Restaurant $restaurant): ?string
    {
        $prices = Product::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_available', true)
            ->whereNull('deleted_at')
            ->with('optionGroups.options')
            ->get()
            // The **base** price: a discount is a promotion, not the brand's level.
            // D-166 — plus the cheapest choice every required group forces, so a dish
            // priced only through its sizes (base 0) counts at its smallest size rather
            // than at zero.
            ->map(fn (Product $product) => OptionSelection::fromPrice($product, base: true))
            ->sort()
            ->values();

        if ($prices->isEmpty()) {
            return null;
        }

        $median = self::median($prices->all());

        return match (true) {
            $median <= self::BUDGET_CEILING => 'budget',
            $median <= self::MID_CEILING => 'mid',
            default => 'premium',
        };
    }

    /** Recompute and persist. Returns the band it settled on. */
    public static function recompute(Restaurant $restaurant): ?string
    {
        $band = self::forRestaurant($restaurant);

        if ($restaurant->price_band !== $band) {
            $restaurant->forceFill(['price_band' => $band])->saveQuietly();
        }

        return $band;
    }

    /** @param  list<float>  $sorted  ascending */
    private static function median(array $sorted): float
    {
        $count = count($sorted);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $sorted[$middle]
            : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }
}
