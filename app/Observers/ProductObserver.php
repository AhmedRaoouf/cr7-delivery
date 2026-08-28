<?php

namespace App\Observers;

use App\Models\Product;
use App\Support\PriceBand;

/** D-60 — the band follows the menu, so it is recomputed whenever the menu changes. */
class ProductObserver
{
    public function saved(Product $product): void
    {
        // Only the three columns the band is derived from can move it.
        if ($product->wasRecentlyCreated
            || $product->wasChanged(['price', 'is_available', 'restaurant_id'])) {
            $this->recompute($product);
        }
    }

    public function deleted(Product $product): void
    {
        $this->recompute($product);
    }

    public function restored(Product $product): void
    {
        $this->recompute($product);
    }

    private function recompute(Product $product): void
    {
        $restaurant = $product->restaurant()->withTrashed()->first();

        if ($restaurant) {
            PriceBand::recompute($restaurant);
        }
    }
}
