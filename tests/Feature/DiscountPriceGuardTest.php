<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\Catalog\LinePrice;
use Tests\TestCase;

/** 2026-11-06 — a discount of 0, or one at or above the price, is not a discount. */
class DiscountPriceGuardTest extends TestCase
{
    public function test_a_zero_discount_charges_the_price(): void
    {
        $product = new Product(['price' => 30.50, 'discount_price' => 0]);

        $this->assertNull($product->liveDiscount());
        $this->assertSame(30.5, LinePrice::effective($product));
    }

    public function test_a_discount_above_the_price_charges_the_price(): void
    {
        $product = new Product(['price' => 50, 'discount_price' => 60]);

        $this->assertNull($product->liveDiscount());
        $this->assertSame(50.0, LinePrice::effective($product));
    }

    public function test_a_real_discount_is_charged(): void
    {
        $product = new Product(['price' => 899, 'discount_price' => 700]);

        $this->assertSame(700.0, $product->liveDiscount());
        $this->assertSame(700.0, LinePrice::effective($product));
    }
}
