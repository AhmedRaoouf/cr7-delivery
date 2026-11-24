<?php

namespace App\Console\Commands;

use App\Observers\OrderObserver;
use App\Support\Catalog\SalesCounters;
use Illuminate\Console\Command;

/**
 * doc 110 — recount every dish's «مرات البيع» and rating and every place's order count
 * from the orders. The backfill after the counters were found never written, and the
 * nightly safety net for any status write that bypassed {@see OrderObserver}.
 */
class RecountSalesCommand extends Command
{
    protected $signature = 'cr7:recount-sales';

    protected $description = 'Recount products.sold_count/rating_avg/rating_count and restaurants.orders_count from delivered orders (doc 110)';

    public function handle(): int
    {
        $products = SalesCounters::refreshProducts();
        $places = SalesCounters::refreshRestaurants();

        $this->line("products rows changed: {$products}");
        $this->line("places rows changed: {$places}");

        return self::SUCCESS;
    }
}
