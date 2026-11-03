<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\Concerns\BuildsListFixtures;
use Tests\Concerns\BuildsTrips;
use Tests\TestCase;

/**
 * doc 99 §6–§7 — D-174: the rating the home asks for once after every basket, «لاحقًا»
 * kept on the server, every captain rated on his own, and «اطلب مرة أخرى».
 */
class HomeOrderCardsTest extends TestCase
{
    use BuildsListFixtures;
    use BuildsTrips;
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 12:00:00');
        $this->setUpListWorld();
        $this->customer = $this->makeCustomer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  Collection<int, Order>  $orders */
    private function deliver(Collection $orders, ?Driver $driver = null, ?Carbon $at = null): void
    {
        Order::query()->whereIn('id', $orders->pluck('id'))->update([
            'status' => 'delivered',
            'delivered_at' => $at ?? now(),
            'driver_id' => $driver?->id,
        ]);
    }

    /** A basket for this customer; the cart the last one emptied is put away first. */
    private function order(array $places, ?User $customer = null): Collection
    {
        $customer ??= $this->customer;
        Cart::query()->withoutGlobalScopes()->where('user_id', $customer->id)->forceDelete();

        return $this->basket($places, $customer);
    }

    private function group(Collection $orders): OrderGroup
    {
        return OrderGroup::query()->findOrFail($orders->first()->order_group_id);
    }

    private function due()
    {
        return $this->actingAsCustomer($this->customer)->getJson('/api/v1/orders/rating-due')->assertOk();
    }

    // ---------------------------------------------------------------- rating-due

    public function test_the_newest_delivered_basket_is_due_with_every_place_and_captain(): void
    {
        $older = $this->order([$this->makeRestaurant('قديم')]);
        $this->deliver($older, $this->captain('كابتن أول'), now()->subDays(2));

        $a = $this->makeRestaurant('سندوتش عمر');
        $b = $this->makeRestaurant('كنج بروست', 31.3850, 31.4850);
        $newest = $this->order([$a, $b]);
        $rami = $this->captain('كابتن رامي');
        $this->deliver($newest, $rami, now()->subHour());

        $due = $this->due();
        $due->assertJsonPath('data.due', true)
            ->assertJsonPath('data.order.id', $this->group($newest)->id)
            ->assertJsonPath('data.order.can_rate', true)
            ->assertJsonPath('data.order.rating_prompt_dismissed', false)
            ->assertJsonCount(2, 'data.order.places')
            ->assertJsonPath('data.order.places.0.name', 'سندوتش عمر')
            ->assertJsonPath('data.order.places.0.rating_badge.source', 'welcome')
            ->assertJsonCount(1, 'data.order.captains')
            ->assertJsonPath('data.order.captains.0.driver_id', $rami->id);

        // Rated: the older one is next, and then nothing.
        $this->actingAsCustomer($this->customer)->postJson('/api/v1/orders/'.$this->group($newest)->id.'/ratings', [
            'restaurants' => [['restaurant_id' => $a->id, 'stars' => 5]],
        ])->assertOk();
        $this->due()->assertJsonPath('data.order.id', $this->group($older)->id);

        $this->actingAsCustomer($this->customer)->postJson('/api/v1/orders/'.$this->group($older)->id.'/ratings/dismiss')->assertOk();
        $this->due()->assertJsonPath('data.due', false)->assertJsonPath('data.order', null);
    }

    public function test_a_basket_still_cooking_at_another_place_is_not_due(): void
    {
        $orders = $this->order([$this->makeRestaurant('أ'), $this->makeRestaurant('ب', 31.3850, 31.4850)]);
        $this->deliver($orders->take(1));

        $this->due()->assertJsonPath('data.due', false);

        // The second kitchen is cancelled: what was delivered may be rated.
        Order::query()->whereKey($orders->last()->id)->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->due()->assertJsonPath('data.due', true)->assertJsonCount(1, 'data.order.places');
    }

    public function test_a_cancelled_basket_and_an_old_one_are_not_due(): void
    {
        $cancelled = $this->order([$this->makeRestaurant('ملغي')]);
        Order::query()->whereIn('id', $cancelled->pluck('id'))->update(['status' => 'cancelled']);

        $old = $this->order([$this->makeRestaurant('قديم جدًا')]);
        $this->deliver($old, at: now()->subDays(8));

        $this->due()->assertJsonPath('data.due', false);

        // Seven days less a minute is still due.
        Order::query()->whereIn('id', $old->pluck('id'))->update(['delivered_at' => now()->subDays(7)->addMinute()]);
        $this->due()->assertJsonPath('data.due', true);
    }

    public function test_later_is_kept_on_the_server_and_only_for_the_owner(): void
    {
        $orders = $this->order([$this->makeRestaurant('مكان')]);
        $this->deliver($orders);
        $group = $this->group($orders);

        $stranger = $this->makeCustomer();
        $this->actingAsCustomer($stranger)->postJson("/api/v1/orders/{$group->id}/ratings/dismiss")->assertNotFound();
        $this->assertNull($group->fresh()->rating_prompt_dismissed_at);

        $first = $this->actingAsCustomer($this->customer)->postJson("/api/v1/orders/{$group->id}/ratings/dismiss")
            ->assertOk()
            ->assertJsonPath('data.rating_prompt_dismissed', true)
            // «لاحقًا» puts the prompt away, not the rating.
            ->assertJsonPath('data.can_rate', true)
            ->json('data.rating_prompt_dismissed_at');

        Carbon::setTestNow('2026-11-03 12:05:00');
        $this->actingAsCustomer($this->customer)->postJson("/api/v1/orders/{$group->id}/ratings/dismiss")
            ->assertOk()
            ->assertJsonPath('data.rating_prompt_dismissed_at', $first);

        // «Another device»: the same account asks again and is not prompted.
        $this->due()->assertJsonPath('data.due', false);

        $this->actingAsCustomer($this->customer)->getJson("/api/v1/orders/{$group->id}")
            ->assertOk()
            ->assertJsonPath('data.rating_prompt_dismissed', true)
            ->assertJsonPath('data.can_rate', true);
    }

    // ------------------------------------------------------------ drivers[]

    public function test_every_captain_of_a_basket_is_rated_on_his_own(): void
    {
        $orders = $this->order([$this->makeRestaurant('أ'), $this->makeRestaurant('ب', 31.3850, 31.4850)]);
        $rami = $this->captain('كابتن رامي');
        $omar = $this->captain('كابتن عمر');
        $this->deliver($orders->take(1), $rami);
        $this->deliver($orders->slice(1), $omar);
        $group = $this->group($orders);

        $this->due()->assertJsonCount(2, 'data.order.captains');

        $this->actingAsCustomer($this->customer)->postJson("/api/v1/orders/{$group->id}/ratings", [
            'drivers' => [
                ['driver_id' => $rami->id, 'stars' => 5, 'tags' => ['سريع']],
                ['driver_id' => $omar->id, 'stars' => 3, 'comment' => 'تأخر قليلًا'],
            ],
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data.rating.drivers')
            ->assertJsonPath('data.rating.driver.driver_id', $rami->id);

        $this->assertSame('5.00', (string) $rami->fresh()->rating_avg);
        $this->assertSame('3.00', (string) $omar->fresh()->rating_avg);
        $this->assertSame(2, Rating::query()->where('rateable_type', 'driver')->count());
    }

    public function test_the_single_driver_field_still_works_and_a_stranger_captain_is_refused(): void
    {
        $orders = $this->order([$this->makeRestaurant('أ')]);
        $rami = $this->captain('كابتن رامي');
        $this->deliver($orders, $rami);
        $group = $this->group($orders);

        $outsider = $this->captain('كابتن آخر');
        $this->actingAsCustomer($this->customer)->postJson("/api/v1/orders/{$group->id}/ratings", [
            'drivers' => [['driver_id' => $outsider->id, 'stars' => 1]],
        ])->assertStatus(422);
        $this->assertSame(0, Rating::query()->count());

        // The 1.0.23 shape, and `drivers[]` naming the same captain is one rating.
        $this->actingAsCustomer($this->customer)->postJson("/api/v1/orders/{$group->id}/ratings", [
            'driver' => ['driver_id' => $rami->id, 'stars' => 2],
            'drivers' => [['driver_id' => $rami->id, 'stars' => 4]],
        ])->assertOk()->assertJsonPath('data.rating.driver.stars', 4);

        $this->assertSame(1, Rating::query()->where('rateable_type', 'driver')->count());
    }

    public function test_the_old_single_driver_shape_alone(): void
    {
        $orders = $this->order([$this->makeRestaurant('أ')]);
        $rami = $this->captain('كابتن رامي');
        $this->deliver($orders, $rami);

        $this->actingAsCustomer($this->customer)->postJson('/api/v1/orders/'.$this->group($orders)->id.'/ratings', [
            'driver' => ['driver_id' => $rami->id, 'stars' => 4],
        ])->assertOk()
            ->assertJsonPath('data.rating.driver.stars', 4)
            ->assertJsonPath('data.rating.drivers.0.stars', 4);
    }

    // ------------------------------------------------------ reorder suggestions

    public function test_reorder_suggestions_are_the_last_places_newest_first_and_still_on_the_app(): void
    {
        $omar = $this->makeRestaurant('سندوتش عمر');
        $king = $this->makeRestaurant('كنج بروست', 31.3850, 31.4850);
        $hidden = $this->makeRestaurant('مكان مخفي', 31.3860, 31.4860);
        $deleted = $this->makeRestaurant('مكان محذوف', 31.3870, 31.4870);

        $first = $this->order([$omar]);
        $this->deliver($first, at: now()->subDays(5));
        $juice = $this->makeRestaurant('عصير', 31.3855, 31.4855);
        $second = $this->order([$king, $juice]);
        $this->deliver($second, at: now()->subDays(3));
        $third = $this->order([$omar]);
        $this->deliver($third, at: now()->subDay());
        $gone = $this->order([$hidden]);
        $this->deliver($gone, at: now()->subHours(5));
        $gone2 = $this->order([$deleted]);
        $this->deliver($gone2, at: now()->subHours(4));
        // Still cooking: not a suggestion yet.
        $this->order([$this->makeRestaurant('قيد التحضير', 31.3880, 31.4880)]);
        // Another customer's basket never shows.
        $this->deliver($this->order([$this->makeRestaurant('غريب', 31.3890, 31.4890)], $this->makeCustomer()));

        $hidden->forceFill(['is_active' => false])->save();
        $deleted->delete();

        $rows = $this->actingAsCustomer($this->customer)->getJson('/api/v1/orders/reorder-suggestions')->assertOk()->json('data');

        $this->assertSame([$this->group($third)->id, $this->group($second)->id], array_column($rows, 'group_id'));
        $this->assertSame([$omar->id, $king->id], array_column(array_column($rows, 'restaurant'), 'id'));

        $row = $rows[0];
        $this->assertSame('سندوتش عمر', $row['restaurant']['name']);
        $this->assertSame('welcome', $row['restaurant']['rating_badge']['source']);
        $this->assertSame(1, $row['items_count']);
        $this->assertEquals((float) $this->group($third)->total, $row['total']);
        $this->assertSame('صنف سندوتش عمر', $row['headline_item']['name']);
        $this->assertNotNull($row['headline_item']['product_id']);
        $this->assertNotNull($row['headline_item']['image']);
        $this->assertSame(now()->subDay()->toIso8601ZuluString('microsecond'), $row['delivered_at']);

        // The two-place basket counts both lines.
        $this->assertSame(2, $rows[1]['items_count']);
    }

    public function test_reorder_suggestions_stop_at_six_places(): void
    {
        foreach (range(1, 8) as $i) {
            $place = $this->makeRestaurant('مكان '.$i, 31.38 + $i / 1000, 31.48);
            $this->deliver($this->order([$place]), at: now()->subHours(10 - $i));
        }

        $rows = $this->actingAsCustomer($this->customer)->getJson('/api/v1/orders/reorder-suggestions')->assertOk()->json('data');
        $this->assertCount(6, $rows);
        $this->assertSame('مكان 8', $rows[0]['restaurant']['name']);

        $this->actingAsCustomer($this->makeCustomer())->getJson('/api/v1/orders/reorder-suggestions')
            ->assertOk()->assertJsonPath('data', []);
    }

    public function test_the_home_cards_are_the_customers_own(): void
    {
        $place = $this->makeRestaurant('مكان');
        $this->actingAsPlace($place)->getJson('/api/v1/orders/rating-due')->assertStatus(403);
        $this->actingAsPlace($place)->getJson('/api/v1/orders/reorder-suggestions')->assertStatus(403);
    }
}
