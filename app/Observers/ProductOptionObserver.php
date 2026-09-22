<?php

namespace App\Observers;

use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Support\PriceBand;

/**
 * D-166 — a required group's cheapest option is part of what a dish costs, so the
 * place's band follows its option groups and options as well as its products.
 */
class ProductOptionObserver
{
    public function saved(ProductOption|ProductOptionGroup $row): void
    {
        $this->recompute($row);
    }

    public function deleted(ProductOption|ProductOptionGroup $row): void
    {
        $this->recompute($row);
    }

    private function recompute(ProductOption|ProductOptionGroup $row): void
    {
        $product = $row instanceof ProductOption ? $row->group?->product : $row->product;
        $restaurant = $product?->restaurant()->withTrashed()->first();

        if ($restaurant) {
            PriceBand::recompute($restaurant);
        }
    }
}
