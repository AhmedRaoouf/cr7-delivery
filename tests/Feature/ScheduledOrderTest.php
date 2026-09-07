<?php

namespace Tests\Feature;

use App\Actions\Customer\ReleaseScheduledOrdersAction;
use App\Models\Cart;
use App\Models\OrderGroup;
use App\Models\ScheduledOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «اطلب في وقت لاحق» — the basket with a time on it.
 *
 * Operator, 2026-09-07: «الكاستمر عايز الأوردر ده الساعة ستة يبدأ يتعمل… وأول ما الساعة
 * ستة تيجي فيقوم رايح يبعت للمطاعم».
 *
 * The two things these tests exist to hold down are the two that would be silently wrong:
 * a scheduled order must not be a live order before its time, and releasing one must not
 * touch whatever the customer is shopping for at that moment.
 */
class ScheduledOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->withHeader('X-App', 'app');
    }

    private function scheduleFor(string $when = '+3 hours'): array
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('ابن البلد');
        $product = $this->makeProduct($restaurant, 'كشري وسط', 60);
        $this->makeCart($customer, [[$restaurant, $product, 2]]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/scheduled-orders', [
            'address_id' => $address->id,
            'payment_method' => 'cash',
            'scheduled_for' => now()->modify($when)->toIso8601String(),
        ]);

        return [$customer, $restaurant, $response];
    }

    public function test_a_scheduled_basket_is_not_an_order_yet(): void
    {
        [$customer, , $response] = $this->scheduleFor();

        $response->assertCreated();
        $this->assertSame('scheduled', $response->json('data.status'));
        $this->assertEqualsWithDelta(120, $response->json('data.subtotal'), 0.001);

        // Nothing a restaurant can accept, nothing a captain can be offered.
        $this->assertSame(0, OrderGroup::query()->count());

        // …and the basket is spent, so the customer cannot also place it by hand.
        $this->assertSame(0, $customer->cart->items()->count());
    }

    public function test_the_window_is_enforced_at_both_ends(): void
    {
        [, , $tooSoon] = $this->scheduleFor('+5 minutes');
        $tooSoon->assertStatus(422)->assertJsonPath('error', 'schedule_too_soon');

        [, , $tooFar] = $this->scheduleFor('+30 days');
        $tooFar->assertStatus(422)->assertJsonPath('error', 'schedule_too_far');
    }

    /**
     * A closed kitchen is not a reason to refuse a booking for tonight.
     *
     * The checkout blocker list is reused wholesale, which would have made «المطعم مغلق»
     * at 14:00 refuse an order for 18:00 — the one thing scheduling is for.
     */
    public function test_a_closed_restaurant_does_not_block_a_later_booking(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $restaurant = $this->makeRestaurant('ابن البلد');
        $restaurant->update(['is_open_manual' => false]);
        $product = $this->makeProduct($restaurant, 'كشري وسط', 60);
        $this->makeCart($customer, [[$restaurant, $product, 1]]);

        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/scheduled-orders', [
            'address_id' => $address->id,
            'payment_method' => 'cash',
            'scheduled_for' => now()->addHours(4)->toIso8601String(),
        ])->assertCreated();
    }

    public function test_the_release_places_a_real_order_through_the_real_action(): void
    {
        [$customer, $restaurant] = $this->scheduleFor();

        $row = ScheduledOrder::query()->firstOrFail();
        Carbon::setTestNow(now()->addHours(4));

        $result = app(ReleaseScheduledOrdersAction::class)->releaseDue();
        Carbon::setTestNow();

        $this->assertSame(1, $result['released']);
        $this->assertSame('released', $row->fresh()->status);

        $group = OrderGroup::query()->firstOrFail();
        $this->assertSame($group->id, $row->fresh()->order_group_id);
        $this->assertSame($customer->id, $group->user_id);
        $this->assertSame(1, $group->orders()->count());
        $this->assertSame($restaurant->id, $group->orders()->first()->restaurant_id);
        // Straight into the machine's first state — no twentieth state anywhere.
        $this->assertSame('pending', $group->orders()->first()->status->value);
    }

    /**
     * The reason `carts.is_scratch` exists.
     *
     * A release at 18:00 rebuilds a basket. If it rebuilt the customer's live one, the
     * order they were halfway through choosing would be placed for them, or destroyed.
     */
    public function test_releasing_does_not_disturb_the_customers_live_basket(): void
    {
        [$customer, $restaurant] = $this->scheduleFor();

        // The customer goes back to shopping in the very cart the schedule emptied.
        $other = $this->makeProduct($restaurant, 'مكرونة بشاميل', 90);
        $customer->cart->items()->create([
            'restaurant_id' => $restaurant->id,
            'product_id' => $other->id,
            'quantity' => 3,
        ]);

        Carbon::setTestNow(now()->addHours(4));
        app(ReleaseScheduledOrdersAction::class)->releaseDue();
        Carbon::setTestNow();

        $live = $customer->fresh()->cart;
        $this->assertNotNull($live);
        $this->assertFalse($live->is_scratch);
        $this->assertSame(1, $live->items()->count());
        $this->assertSame(3, $live->items()->first()->quantity);

        // And the throwaway cart is gone rather than lingering under the same user.
        $this->assertSame(0, Cart::query()->where('is_scratch', true)->count());
    }

    public function test_a_release_that_slept_through_its_moment_fails_loudly(): void
    {
        $this->scheduleFor();

        Carbon::setTestNow(now()->addHours(9));
        $result = app(ReleaseScheduledOrdersAction::class)->releaseDue();
        Carbon::setTestNow();

        $this->assertSame(1, $result['failed']);
        $row = ScheduledOrder::query()->firstOrFail();
        $this->assertSame('failed', $row->status);
        $this->assertSame('schedule_stale', $row->failure_code);
        $this->assertSame(0, OrderGroup::query()->count());
    }

    public function test_the_customer_can_cancel_a_scheduled_order_and_the_sweep_skips_it(): void
    {
        [$customer] = $this->scheduleFor();
        $row = ScheduledOrder::query()->firstOrFail();

        Sanctum::actingAs($customer);
        $this->deleteJson("/api/v1/scheduled-orders/{$row->id}")->assertOk();
        $this->assertSame('cancelled', $row->fresh()->status);

        Carbon::setTestNow(now()->addHours(4));
        $this->assertSame(['released' => 0, 'failed' => 0], app(ReleaseScheduledOrdersAction::class)->releaseDue());
        Carbon::setTestNow();
    }

    public function test_the_restaurant_sees_only_its_own_lines(): void
    {
        [, $restaurant] = $this->scheduleFor();

        Sanctum::actingAs($restaurant->owner);
        $this->withHeader('X-App', 'restaurant');

        $response = $this->getJson('/api/v1/restaurant/scheduled-orders');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEqualsWithDelta(120, $response->json('data.0.subtotal'), 0.001);
        // The kitchen is not told the delivery fee or the basket total, and has no
        // reason to hold the customer's phone number for an order that is not placed.
        $this->assertNull($response->json('data.0.total'));
        $this->assertNull($response->json('data.0.customer.phone'));
    }
}
