<?php

namespace Tests\Feature;

use App\Actions\Customer\CreateOrderAction;
use App\Models\Order;
use App\Models\Rating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D-80 — one rating per basket, and the order payload says so.
 *
 * Operator, 2026-09-11: «لما العميل يخلص طلب يظهر على طول التقييم… ولما يقيّم يتحدّث
 * التقييم على حسب المتوسط… ولما أقيّم مرة خلاص ما أقيّمش تاني — مرة واحدة بس، تقييم الـ
 * Delivery والمكان».
 *
 * Before this: `updateOrCreate` with no guard, so every reopening of a delivered receipt
 * offered the stars again and quietly overwrote the earlier answer; `is_rated` existed in
 * the app's model and was never set by the API, so the button never went away; and the
 * average counted hidden reviews an operator had taken down.
 */
class RateOrderOnceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_basket_is_rated_once_and_says_so(): void
    {
        $this->seedLocation();

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('ابن البلد');
        $product = $this->makeProduct($restaurant, 'كشري لوكس', 100);
        $this->makeCart($customer, [[$restaurant, $product, 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        Sanctum::actingAs($customer);

        // Still cooking: the rating is not offered, because a basket with a kitchen still
        // working is not over and the one rating would be spent on half an order.
        $this->withHeaders(['X-App' => 'app'])
            ->getJson('/api/v1/orders/'.$group->id)
            ->assertOk()
            ->assertJsonPath('data.can_rate', false)
            ->assertJsonPath('data.is_rated', false);

        Order::query()->where('order_group_id', $group->id)
            ->update(['status' => 'delivered', 'delivered_at' => now()]);

        $this->withHeaders(['X-App' => 'app'])
            ->getJson('/api/v1/orders/'.$group->id)
            ->assertOk()
            ->assertJsonPath('data.can_rate', true);

        // Skipping every step used to post an empty body and answer «تم إرسال التقييم».
        $this->withHeaders(['X-App' => 'app'])
            ->postJson('/api/v1/orders/'.$group->id.'/ratings', ['restaurants' => []])
            ->assertStatus(422)
            ->assertJsonPath('error', 'rating_required');

        $this->withHeaders(['X-App' => 'app'])
            ->postJson('/api/v1/orders/'.$group->id.'/ratings', [
                'restaurants' => [['restaurant_id' => $restaurant->id, 'stars' => 5, 'comment' => 'ممتاز']],
            ])
            ->assertOk()
            // The answer carries the order, so the app never guesses whether the button
            // is spent — it redraws from this.
            ->assertJsonPath('data.is_rated', true)
            ->assertJsonPath('data.can_rate', false)
            ->assertJsonPath('data.rating.restaurants.0.stars', 5);

        $this->assertSame('5.00', (string) $restaurant->fresh()->rating_avg);
        $this->assertSame(1, $restaurant->fresh()->rating_count);

        // Second time: refused, and the first answer stands.
        $this->withHeaders(['X-App' => 'app'])
            ->postJson('/api/v1/orders/'.$group->id.'/ratings', [
                'restaurants' => [['restaurant_id' => $restaurant->id, 'stars' => 1]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'already_rated');

        $this->assertSame(5, (int) Rating::query()->where('rateable_type', 'restaurant')->value('stars'));
        $this->assertSame('5.00', (string) $restaurant->fresh()->rating_avg);
    }

    public function test_a_hidden_review_is_not_counted_in_the_average(): void
    {
        $this->seedLocation();

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $product = $this->makeProduct($restaurant, 'سندوتش فراخ', 60);
        $this->makeCart($customer, [[$restaurant, $product, 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid(),
        );

        Order::query()->where('order_group_id', $group->id)
            ->update(['status' => 'delivered', 'delivered_at' => now()]);

        // An abusive one-star an operator has already taken down.
        Rating::query()->create([
            'order_id' => Order::query()->where('order_group_id', $group->id)->value('id'),
            'user_id' => $this->makeCustomer()->id,
            'rateable_type' => 'restaurant',
            'rateable_id' => $restaurant->id,
            'stars' => 1,
            'is_hidden' => true,
        ]);

        Sanctum::actingAs($customer);
        $this->withHeaders(['X-App' => 'app'])
            ->postJson('/api/v1/orders/'.$group->id.'/ratings', [
                'restaurants' => [['restaurant_id' => $restaurant->id, 'stars' => 5]],
            ])
            ->assertOk();

        // Hiding the words and keeping the punishment is not hiding it.
        $this->assertSame('5.00', (string) $restaurant->fresh()->rating_avg);
        $this->assertSame(1, $restaurant->fresh()->rating_count);
    }
}
