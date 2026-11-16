<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Restaurant;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTrips;
use Tests\TestCase;

/**
 * doc 107 §5 — a place's own order number is unique per place per day. Places type
 * «1», «12», «123» and repeat them; a repeat reached the unique index and answered 500,
 * so «بدء التحضير» failed with a generic error (16–92 log lines a day since 2026-11-09).
 */
class RestaurantOrderRefTakenTest extends TestCase
{
    use BuildsTrips;
    use RefreshDatabase;

    private Restaurant $place;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        Settings::set('delivery.first_order_free', false);
        Settings::set('ring.enabled', false);
        Settings::set('dispatch.strategy', 'broadcast');
        Carbon::setTestNow(Carbon::parse('2026-11-16 16:00:00', 'UTC'));

        $this->place = $this->makeRestaurant('كنج بروست', 31.3840, 31.4840);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function asPlace(): static
    {
        Sanctum::actingAs($this->place->owner);

        return $this->withHeader('X-App', 'restaurant');
    }

    private function order(OrderStatus $status, ?string $ref = null): Order
    {
        $order = $this->basket([$this->place])->first();
        $order->update(['status' => $status, 'prep_minutes' => 10, 'restaurant_order_ref' => $ref]);

        return $order->fresh();
    }

    public function test_starting_with_a_number_used_today_names_it_instead_of_failing(): void
    {
        $this->order(OrderStatus::Preparing, '12');
        $second = $this->order(OrderStatus::Confirmed);

        $this->asPlace()->postJson("/api/v1/restaurant/orders/{$second->id}/start-preparing", ['restaurant_order_ref' => '12'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'duplicate_restaurant_order_ref')
            ->assertJsonPath('message', "رقم الطلب \u{202A}12\u{202C} مستخدم لطلب آخر اليوم. اكتب رقم الطلب كما هو في نظام المطعم.");

        $this->assertSame(OrderStatus::Confirmed, $second->fresh()->status, 'nothing half-written');

        $this->asPlace()->postJson("/api/v1/restaurant/orders/{$second->id}/start-preparing", ['restaurant_order_ref' => '13'])
            ->assertOk()
            ->assertJsonPath('data.status', 'preparing');
    }

    public function test_accepting_and_changing_the_number_refuse_a_repeat_the_same_way(): void
    {
        $this->order(OrderStatus::Accepted, '5');
        $pending = $this->order(OrderStatus::Pending);
        $accepted = $this->order(OrderStatus::Accepted, '6');

        $this->asPlace()->postJson("/api/v1/restaurant/orders/{$pending->id}/accept", ['prep_minutes' => 15, 'restaurant_order_ref' => '5'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'duplicate_restaurant_order_ref');
        $this->assertSame(OrderStatus::Pending, $pending->fresh()->status);

        $this->asPlace()->patchJson("/api/v1/restaurant/orders/{$accepted->id}/order-ref", ['restaurant_order_ref' => '5'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'duplicate_restaurant_order_ref');
        $this->assertSame('6', $accepted->fresh()->restaurant_order_ref);
    }

    public function test_the_order_resending_its_own_number_is_not_a_repeat(): void
    {
        $order = $this->order(OrderStatus::Confirmed, '7');

        $this->asPlace()->postJson("/api/v1/restaurant/orders/{$order->id}/start-preparing", ['restaurant_order_ref' => '7'])
            ->assertOk();
    }

    public function test_yesterdays_number_is_free_today(): void
    {
        $old = $this->order(OrderStatus::Delivered, '1');
        $old->forceFill(['business_date' => '2026-11-15'])->saveQuietly();
        $today = $this->order(OrderStatus::Confirmed);

        $this->asPlace()->postJson("/api/v1/restaurant/orders/{$today->id}/start-preparing", ['restaurant_order_ref' => '1'])
            ->assertOk();
    }
}
