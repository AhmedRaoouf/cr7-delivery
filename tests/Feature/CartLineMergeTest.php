<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «لما بضيف في السلة كانز وأزود وأزود بيحطهم في السلة تلت عناصر مش عنصر واحد العدد منه تلاتة».
 *
 * `AddCartItemAction` called `create()` on every add, so the same dish tapped three
 * times became three lines of one each. The basket read as three items, each stepper
 * said «1», and «امسح الكانز» removed a third of it.
 */
class CartLineMergeTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();

        $restaurant = $this->makeRestaurant('بوكس ع السريع');
        $this->product = $this->makeProduct($restaurant, 'كانز', 15.0);
        $this->customer = $this->makeCustomer();
        Sanctum::actingAs($this->customer);
    }

    /** @param  list<int>  $optionIds */
    private function add(array $optionIds = [], ?string $note = null, int $quantity = 1): void
    {
        $this->withHeaders(['X-App' => 'app', 'X-Role' => 'customer'])
            ->postJson('/api/v1/cart/items', array_filter([
                'product_id' => $this->product->id,
                'quantity' => $quantity,
                'option_ids' => $optionIds === [] ? null : $optionIds,
                'note' => $note,
            ], fn ($value) => $value !== null))
            ->assertSuccessful();
    }

    public function test_three_taps_on_the_same_dish_are_one_line_of_three(): void
    {
        $this->add();
        $this->add();
        $this->add();

        $items = $this->customer->cart->items()->get();

        $this->assertCount(1, $items, 'the same dish added three times made three lines');
        $this->assertSame(3, (int) $items->first()->quantity);
    }

    public function test_a_quantity_greater_than_one_folds_into_the_same_line(): void
    {
        $this->add(quantity: 2);
        $this->add(quantity: 3);

        $items = $this->customer->cart->items()->get();

        $this->assertCount(1, $items);
        $this->assertSame(5, (int) $items->first()->quantity);
    }

    public function test_a_different_note_is_a_different_thing_to_cook(): void
    {
        $this->add(note: 'بدون ثلج');
        $this->add();

        $this->assertCount(2, $this->customer->cart->items()->get());
    }

    public function test_a_different_option_set_stays_its_own_line(): void
    {
        $group = $this->product->optionGroups()->create([
            'name_ar' => 'الحجم',
            'name_en' => 'Size',
            'selection_type' => 'single',
            'is_required' => true,
            'min_select' => 1,
            'max_select' => 1,
        ]);
        $small = $group->options()->create(['name_ar' => 'صغير', 'name_en' => 'S', 'price_delta' => 0]);
        $large = $group->options()->create(['name_ar' => 'كبير', 'name_en' => 'L', 'price_delta' => 5]);

        $this->add([$small->id]);
        $this->add([$large->id]);
        $this->add([$large->id]);

        $items = $this->customer->cart->items()->with('options')->get();

        $this->assertCount(2, $items, 'two sizes are two lines');
        $this->assertSame(
            [1, 2],
            $items->pluck('quantity')->map(fn ($q) => (int) $q)->sort()->values()->all(),
        );
    }
}
