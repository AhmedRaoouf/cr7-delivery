<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «بدخلش على المطعم وأعمل زائد لمنتج معين — ما بيشتغلش».
 *
 * FR-MENU-08 says «+» on a list row may not add a dish that still has a choice
 * to make; it opens `CUS-15` instead. The app decided that by looking at
 * `option_groups` — which `ProductDetailResource` sends and `ProductResource`
 * does not. So on every list, every search result and every favourite, the array
 * was empty, the question was answered «no», and the quick-add went to the server
 * without the required option.
 *
 * The dish's own screen worked, because that is the one payload carrying the
 * groups. Which is exactly the shape the report describes.
 */
class ProductRequiresConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
    }

    /** @param array{required: bool, options: int}|null $group */
    private function product(?array $group): Product
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $product = $this->makeProduct($restaurant, 'كفتة مشوية', 100.0);

        if ($group !== null) {
            $created = $product->optionGroups()->create([
                'name_ar' => 'الحجم',
                'name_en' => 'Size',
                'selection_type' => 'single',
                'is_required' => $group['required'],
                'min_select' => $group['required'] ? 1 : 0,
                'max_select' => 1,
            ]);

            for ($i = 0; $i < $group['options']; $i++) {
                $created->options()->create([
                    'name_ar' => 'خيار '.$i,
                    'name_en' => 'Option '.$i,
                    'price_delta' => 0,
                ]);
            }
        }

        return $product->fresh();
    }

    public function test_a_dish_with_a_required_choice_says_so_on_a_list_row(): void
    {
        $product = $this->product(['required' => true, 'options' => 3]);

        $this->assertTrue($product->requiresConfiguration());
    }

    public function test_a_dish_with_no_groups_at_all_can_be_added_straight(): void
    {
        $this->assertFalse($this->product(null)->requiresConfiguration());
    }

    public function test_an_optional_group_is_not_a_stop(): void
    {
        $product = $this->product(['required' => false, 'options' => 3]);

        $this->assertFalse($product->requiresConfiguration());
    }

    public function test_a_required_group_with_one_option_still_has_to_be_answered(): void
    {
        // Read from the device: «+» sent `{product_id: 122, quantity: 1}` and
        // the cart answered 422 «يجب اختيار الحجم». Nothing anywhere fills in
        // the only possible answer, so «the group offers no real choice» was a
        // rule this side of the wire held alone.
        $product = $this->product(['required' => true, 'options' => 1]);

        $this->assertTrue($product->requiresConfiguration());
    }

    public function test_the_flag_and_the_cart_are_the_same_rule(): void
    {
        // The drift this closes: a row saying it can be quick-added, and a cart
        // that refuses it. Whatever the flag says, the cart has to agree — so
        // ask both about the same dish.
        $product = $this->product(['required' => true, 'options' => 1]);

        Sanctum::actingAs($this->makeCustomer());

        $this->withHeaders(['X-App' => 'app', 'X-Role' => 'customer'])
            ->postJson('/api/v1/cart/items', [
                'product_id' => $product->id,
                'quantity' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');

        $this->assertTrue(
            $product->requiresConfiguration(),
            'the cart refused a dish the list row offered to add in one tap',
        );
    }

    public function test_the_list_payload_carries_the_answer(): void
    {
        $product = $this->product(['required' => true, 'options' => 2]);

        $response = $this->getJson("/api/v1/restaurants/{$product->restaurant_id}/products")
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $product->id);

        $this->assertArrayHasKey('requires_configuration', $row);
        $this->assertTrue($row['requires_configuration']);
    }
}
