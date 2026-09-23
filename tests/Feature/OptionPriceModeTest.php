<?php

namespace Tests\Feature;

use App\Models\OrderGroup;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\Restaurant;
use App\Support\Catalog\OptionSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * doc 78 — «نص كيلو = 500» is the price of half a kilo, not 500 on top of the kilo.
 *
 * A place priced a 950 kebab's third and half as 350 and 500; every option was an extra,
 * so the customer was asked 1 300 for a third. A group can now set the price itself.
 */
class OptionPriceModeTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    private Restaurant $grill;

    private Product $kebab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();

        $this->grill = $this->makeRestaurant('مشويات الشيف');
        $this->kebab = $this->makeProduct($this->grill, 'كباب', 950.0);
    }

    /** @return array{0: ProductOptionGroup, 1: array<string, ProductOption>, 2: ProductOption} */
    private function sizesAndBread(): array
    {
        $response = $this->actingAsPlace($this->grill)
            ->postJson("/api/v1/restaurant/products/{$this->kebab->id}/option-groups", [
                'name_ar' => 'الحجم', 'name_en' => 'Size',
                'selection_type' => 'single', 'price_mode' => 'final', 'is_required' => true,
                'options' => [
                    // 0 = the dish's own price: the kilo needs no number.
                    ['name_ar' => 'كيلو', 'name_en' => 'Kilo', 'price_delta' => 0],
                    ['name_ar' => 'نص كيلو', 'name_en' => 'Half', 'price_delta' => 500],
                    ['name_ar' => 'تلت كيلو', 'name_en' => 'Third', 'price_delta' => 350],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.price_mode', 'final');

        $sizes = ProductOptionGroup::query()->findOrFail($response->json('data.id'));
        $bread = $this->kebab->optionGroups()->create([
            'name_ar' => 'إضافات', 'name_en' => 'Extras', 'selection_type' => 'multiple', 'max_select' => 2, 'sort_order' => 1,
        ])->options()->create(['name_ar' => 'عيش', 'name_en' => 'Bread', 'price_delta' => 10]);

        return [$sizes, $sizes->options()->get()->keyBy('name_en')->all(), $bread];
    }

    public function test_a_price_setting_group_is_one_choice_and_one_per_dish(): void
    {
        $url = "/api/v1/restaurant/products/{$this->kebab->id}/option-groups";

        $this->actingAsPlace($this->grill)->postJson($url, [
            'name_ar' => 'الوزن', 'name_en' => 'Weight', 'selection_type' => 'multiple', 'price_mode' => 'final',
        ])->assertStatus(422)->assertJsonValidationErrors('selection_type');

        [$sizes] = $this->sizesAndBread();

        $this->actingAsPlace($this->grill)->postJson($url, [
            'name_ar' => 'الوزن', 'name_en' => 'Weight', 'selection_type' => 'single', 'price_mode' => 'final',
        ])->assertStatus(422)->assertJsonValidationErrors('price_mode');

        // A multiple group switched to `final` without saying «multiple» becomes single.
        $extras = $this->kebab->optionGroups()->where('name_en', 'Extras')->sole();
        $this->actingAsPlace($this->grill)->patchJson("/api/v1/restaurant/products/{$this->kebab->id}/option-groups/{$sizes->id}", ['price_mode' => 'add'])->assertOk();
        $this->actingAsPlace($this->grill)->patchJson("/api/v1/restaurant/products/{$this->kebab->id}/option-groups/{$extras->id}", ['price_mode' => 'final'])
            ->assertOk()
            ->assertJsonPath('data.price_mode', 'final')
            ->assertJsonPath('data.selection_type', 'single')
            ->assertJsonPath('data.max_select', 1);

        // An untouched group stays an extra.
        $this->assertSame('add', ProductOptionGroup::query()->create([
            'product_id' => $this->kebab->id, 'name_ar' => 'x', 'name_en' => 'x', 'selection_type' => 'single',
        ])->refresh()->price_mode->value);
    }

    public function test_the_customer_sees_the_size_price_and_pays_it(): void
    {
        [, $size, $bread] = $this->sizesAndBread();
        $customer = $this->makeCustomer();

        $sheet = $this->actingAsCustomer($customer)->getJson("/api/v1/products/{$this->kebab->id}")->assertOk()->json('data');
        $this->assertSame('final', $sheet['option_groups'][0]['price_mode']);
        $this->assertEquals([950, 500, 350], collect($sheet['option_groups'][0]['options'])->pluck('price')->all());
        // What a 1.0.16 app adds to `price` to reach the same totals.
        $this->assertEquals([0, -450, -600], collect($sheet['option_groups'][0]['options'])->pluck('price_delta')->all());
        $this->assertEquals(350, $sheet['from_price']);
        $this->assertSame('add', $sheet['option_groups'][1]['price_mode']);
        $this->assertEquals(10, $sheet['option_groups'][1]['options'][0]['price']);

        // Third + bread, ×2: (350 + 10) × 2 = 720 — not (950 + 350 + 10) × 2.
        $line = $this->actingAsCustomer($customer)->postJson('/api/v1/cart/items', [
            'product_id' => $this->kebab->id, 'option_ids' => [$size['Third']->id, $bread->id], 'quantity' => 2,
        ])->assertCreated()->json('data.groups.0.items.0');

        $this->assertEquals(350, $line['unit_price']);
        $this->assertEquals(10, $line['options_total']);
        $this->assertEquals(720, $line['line_total']);
        $third = collect($line['options'])->firstWhere('id', $size['Third']->id);
        $this->assertSame('final', $third['price_mode']);
        $this->assertEquals(350, $third['price']);
        $this->assertEquals(0, $third['price_delta']);

        // The kilo with no number of its own is the dish's price.
        $this->actingAsCustomer($customer)->patchJson("/api/v1/cart/items/{$line['id']}", ['option_ids' => [$size['Kilo']->id], 'quantity' => 1])
            ->assertOk()
            ->assertJsonPath('data.groups.0.items.0.line_total', 950);

        // A discount on the dish does not reach a size priced on its own.
        $this->kebab->update(['discount_price' => 900]);
        $this->actingAsCustomer($customer)->patchJson("/api/v1/cart/items/{$line['id']}", ['option_ids' => [$size['Half']->id]])
            ->assertOk()
            ->assertJsonPath('data.groups.0.items.0.line_total', 500);
    }

    public function test_the_order_is_billed_and_remembered_at_the_size_price(): void
    {
        [, $size, $bread] = $this->sizesAndBread();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);

        $this->actingAsCustomer($customer)->postJson('/api/v1/cart/items', [
            'product_id' => $this->kebab->id, 'option_ids' => [$size['Half']->id, $bread->id],
        ])->assertCreated();

        $quote = $this->actingAsCustomer($customer)->postJson('/api/v1/checkout/quote', ['address_id' => $address->id, 'payment_method' => 'cash'])
            ->assertOk()->json('data');
        $this->assertEquals(510, $quote['subtotal']);

        $this->actingAsCustomer($customer)->postJson('/api/v1/orders', ['address_id' => $address->id, 'payment_method' => 'cash'], ['Idempotency-Key' => 'half'])
            ->assertCreated();
        $group = OrderGroup::query()->latest('id')->firstOrFail();
        $this->assertEquals(510, (float) $group->subtotal);

        // The place reprices the half afterwards; the order keeps what was charged.
        $size['Half']->update(['price_delta' => 999]);

        $item = $this->actingAsCustomer($customer)->getJson("/api/v1/orders/{$group->id}")->assertOk()->json('data.sub_orders.0.items.0');
        $this->assertEquals(500, $item['unit_price']);
        $this->assertEquals(510, $item['line_total']);
        $half = collect($item['options'])->firstWhere('option_id', $size['Half']->id);
        $this->assertSame('final', $half['price_mode']);
        $this->assertEquals(500, $half['price']);
        $this->assertEquals(0, $half['price_delta']);

        // Staff read the same snapshot.
        $placeOrder = $this->actingAsPlace($this->grill)->getJson('/api/v1/restaurant/orders/'.$group->orders()->value('id'))->assertOk()->json('data');
        $this->assertEquals(500, $placeOrder['items'][0]['unit_price_snapshot']);
        $this->assertSame('final', collect($placeOrder['items'][0]['options'])->firstWhere('product_option_id', $size['Half']->id)['price_mode_snapshot']);
    }

    public function test_a_scheduled_basket_and_the_cards_floor_use_the_size_price(): void
    {
        [$sizes, $size] = $this->sizesAndBread();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->actingAsCustomer($customer)->postJson('/api/v1/cart/items', ['product_id' => $this->kebab->id, 'option_ids' => [$size['Third']->id], 'quantity' => 2])->assertCreated();

        $line = $this->actingAsCustomer($customer)->postJson('/api/v1/scheduled-orders', [
            'address_id' => $address->id, 'payment_method' => 'cash', 'scheduled_for' => now()->addHours(3)->toIso8601String(),
        ])->assertCreated()->json('data.lines.0');
        $this->assertEquals(350, $line['unit_price']);
        $this->assertEquals(700, $line['line_total']);
        $this->assertSame('final', $line['option_details'][0]['price_mode']);

        // Optional sizes: the dish alone is still a choice, so the floor is the cheaper.
        $sizes->update(['is_required' => false, 'min_select' => 0]);
        $size['Third']->update(['price_delta' => 1200]);
        $size['Half']->update(['price_delta' => 1100]);
        $this->assertEquals(950, OptionSelection::fromPrice($this->kebab->fresh()->load('optionGroups.options')));
        $size['Third']->update(['price_delta' => 300]);
        $this->assertEquals(300, OptionSelection::fromPrice($this->kebab->fresh()->load('optionGroups.options')));
    }
}
