<?php

namespace Tests\Feature;

use App\Models\PromoCode;
use App\Support\CheckoutPricer;
use App\Support\FirstOrderPerk;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * doc 107 — «ي شنو التطبيق جاى كل التوصيل مجانا» (Ahmed Raouf, 2026-11-16).
 *
 * Raising the area fees from the web form also saved «توصيل مجاني من» as 0, because
 * the field started at 0. The form says «صفر يعني بلا عرض توصيل مجاني»; the pricer read
 * it as «free from 0 ج.م», so every basket in every edited area paid no delivery for
 * two hours and the captains earned 0 on those orders.
 */
class ZeroIsNoFreeDeliveryTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();
        Settings::set(FirstOrderPerk::SETTING, 0, 'delivery');
    }

    private function quote(float $price = 100): array
    {
        $customer = $this->makeCustomer();
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $product = $this->makeProduct($restaurant, 'سندوتش جمبري', $price);
        $cart = $this->makeCart($customer, [[$restaurant, $product, 1]]);

        return app(CheckoutPricer::class)->price($customer, $cart, $this->makeAddress($customer), null, 'cash');
    }

    public function test_the_web_form_saving_zero_leaves_the_area_with_no_offer(): void
    {
        // Exactly what the web zone dialog sent on 2026-11-16: the new fee, and the
        // threshold field's starting 0.
        $this->actingAsAdmin($this->globalAdmin())
            ->patchJson("/api/v1/admin/zones/{$this->zone->id}", [
                'delivery_fee' => 23,
                'free_delivery_over' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('data.free_delivery_over', null);

        $this->assertNull(DB::table('service_zones')->where('id', $this->zone->id)->value('free_delivery_over'));

        $quote = $this->quote();
        $this->assertFalse($quote['free_delivery_applied']);
        $this->assertSame(23.0, $quote['delivery_fee']);
    }

    public function test_a_zero_already_stored_charges_the_fee_and_is_not_advertised(): void
    {
        // A row written before the fix, straight into the table.
        DB::table('service_zones')->where('id', $this->zone->id)->update(['free_delivery_over' => 0]);

        $quote = $this->quote();
        $this->assertFalse($quote['free_delivery_applied']);
        $this->assertSame(20.0, $quote['delivery_fee']);

        $this->getJson('/api/v1/restaurants?filter[free_delivery]=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_real_threshold_still_waives_the_fee_from_its_amount(): void
    {
        $this->zone->update(['free_delivery_over' => 150]);

        $below = $this->quote(100);
        $this->assertFalse($below['free_delivery_applied']);
        $this->assertSame(20.0, $below['delivery_fee']);

        $above = $this->quote(150);
        $this->assertTrue($above['free_delivery_applied']);
        $this->assertSame(0.0, $above['delivery_fee']);
    }

    public function test_emptying_the_field_removes_the_offer(): void
    {
        $this->zone->update(['free_delivery_over' => 150]);

        $this->actingAsAdmin($this->globalAdmin())
            ->patchJson("/api/v1/admin/zones/{$this->zone->id}", ['free_delivery_over' => null])
            ->assertOk()
            ->assertJsonPath('data.free_delivery_over', null);

        $this->assertFalse($this->quote(500)['free_delivery_applied']);
    }

    public function test_a_promo_cap_of_zero_means_no_cap(): void
    {
        // «أقصى خصم: صفر يعني بلا حد أقصى» — the same sentence on the promo form.
        $promo = PromoCode::query()->create([
            'code' => 'ZERO0',
            'title_ar' => 'خصم',
            'title_en' => 'Discount',
            'type' => 'percent',
            'value' => 10,
            'max_discount' => 0,
            'is_active' => true,
        ]);

        $this->assertNull($promo->fresh()->max_discount);
    }

    public function test_the_migration_clears_zeros_already_written(): void
    {
        DB::table('service_zones')->where('id', $this->zone->id)->update(['free_delivery_over' => 0]);
        DB::table('service_zones')->where('id', $this->otherZone->id)->update(['free_delivery_over' => 300]);

        (require database_path('migrations/2026_11_16_090000_zero_is_no_offer_and_no_cap.php'))->up();

        $this->assertNull(DB::table('service_zones')->where('id', $this->zone->id)->value('free_delivery_over'));
        $this->assertEquals(300, DB::table('service_zones')->where('id', $this->otherZone->id)->value('free_delivery_over'));
    }

}
