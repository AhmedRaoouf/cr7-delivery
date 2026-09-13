<?php

namespace Tests\Feature;

use App\Models\OrderGroup;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D-114 — «الحد الاقصي للعميل انه يطلب من مطعمين بس مينفعش اكتر من كدا وبرده الادمن بيتحكم
 * فيها».
 */
class BasketPlaceLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    /** @var list<Product> one dish from each of five places */
    private array $dishes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();

        foreach (['سندوتش عمر', 'ناصر القياس', 'الركن العربي', 'ابن البلد', 'كنج بروست'] as $name) {
            $restaurant = $this->makeRestaurant($name);
            $this->dishes[] = $this->makeProduct($restaurant, 'طبق '.$name, 60);
        }

        $this->customer = $this->makeCustomer();
        Sanctum::actingAs($this->customer);
        $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar']);
    }

    private function add(int $place, int $quantity = 1): TestResponse
    {
        return $this->postJson('/api/v1/cart/items', ['product_id' => $this->dishes[$place]->id, 'quantity' => $quantity]);
    }

    public function test_a_third_place_is_refused_in_words(): void
    {
        $this->add(0)->assertCreated();
        $this->add(1)->assertCreated();

        $this->add(2)
            ->assertStatus(422)
            // The code the shipped app already switches on.
            ->assertJsonPath('error', 'too_many_restaurants')
            ->assertJsonPath('message', 'يمكنك الطلب من مكانين كحد أقصى في الطلب الواحد')
            ->assertJsonPath('meta.max', 2);

        // More of a place already in the basket is not a new place.
        $this->add(1)->assertCreated();
    }

    public function test_the_limit_is_the_operators_and_the_sentence_follows_it(): void
    {
        Settings::set('cart.max_restaurants', 3, 'cart');

        $this->add(0)->assertCreated();
        $this->add(1)->assertCreated();
        $this->add(2)->assertCreated();

        $this->add(3)
            ->assertStatus(422)
            ->assertJsonPath('message', 'يمكنك الطلب من ثلاثة أماكن كحد أقصى في الطلب الواحد')
            ->assertJsonPath('meta.max', 3);
    }

    public function test_one_place_reads_as_one_place(): void
    {
        Settings::set('cart.max_restaurants', 1, 'cart');

        $this->add(0)->assertCreated();
        $this->add(1)
            ->assertStatus(422)
            ->assertJsonPath('message', 'يمكنك الطلب من مكان واحد كحد أقصى في الطلب الواحد');
    }

    /** A basket filled before the limit dropped is refused by name, never trimmed in silence. */
    public function test_a_basket_over_a_lowered_limit_is_blocked_at_checkout(): void
    {
        Settings::set('cart.max_restaurants', 3, 'cart');
        $this->add(0)->assertCreated();
        $this->add(1)->assertCreated();
        $this->add(2)->assertCreated();

        Settings::set('cart.max_restaurants', 2, 'cart');
        $address = $this->makeAddress($this->customer);

        $quote = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address->id, 'payment_method' => 'cash'])
            ->assertOk();

        $blocker = collect($quote->json('data.blockers'))->firstWhere('code', 'too_many_restaurants');
        $this->assertSame('يمكنك الطلب من مكانين كحد أقصى في الطلب الواحد', $blocker['message']);
        $this->assertSame(3, $quote->json('data.restaurant_count'));

        $this->postJson('/api/v1/orders', ['address_id' => $address->id, 'payment_method' => 'cash'], ['Idempotency-Key' => 'over-limit'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'too_many_restaurants');

        $this->assertSame(0, OrderGroup::query()->count());
        // Nothing was taken out of the basket for them.
        $this->assertSame(3, $this->customer->cart->items()->distinct()->count('restaurant_id'));
    }

    public function test_the_most_of_one_item_is_the_operators(): void
    {
        Settings::set('cart.max_qty_per_line', 5, 'cart');

        $this->add(0, 6)
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('errors.quantity.0', 'أقصى كمية للصنف الواحد ‪5‬');

        // One tap at a time does not walk past it either.
        $this->add(0, 3)->assertCreated();
        $this->add(0, 3)
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'أقصى كمية للصنف الواحد ‪5‬');
        $this->add(0, 2)->assertCreated();

        $this->assertSame(5, (int) $this->customer->cart->items()->value('quantity'));
    }
}
