<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_a_required_group_with_one_option_is_a_fact_not_a_choice(): void
    {
        // Stopping the customer to confirm the only possible answer is friction
        // for nothing, and the app's own rule says the same.
        $product = $this->product(['required' => true, 'options' => 1]);

        $this->assertFalse($product->requiresConfiguration());
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
