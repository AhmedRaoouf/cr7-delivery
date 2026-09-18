<?php

namespace Tests\Feature\Lists;

use App\Models\ErrandRequest;
use App\Models\Notification;
use App\Models\Restaurant;
use App\Models\ScheduledOrder;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * Batch 68 — ADMIN-LISTS.md §2–§4: the lists a place, a captain and a customer read about
 * their own rows, one test per list.
 */
class OwnListFiltersTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    private Restaurant $place;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();
        $this->place = $this->makeRestaurant('سندوتش عمر');
    }

    // ------------------------------------------------------------------ place

    public function test_the_place_board_by_queues_status_payment_guest_and_sort(): void
    {
        $new = $this->orderFor($this->place, ['status' => 'pending', 'total' => 80]);
        $cooking = $this->orderFor($this->place, ['status' => 'preparing', 'payment_method' => 'instapay', 'total' => 300, 'prep_due_at' => now()->subMinute()]);
        $guest = $this->orderFor($this->place, ['status' => 'delivered', 'origin' => 'restaurant_direct', 'guest_customer_phone' => '01077778888', 'total' => 150]);
        $this->orderFor($this->makeRestaurant('كنج بروست'), ['status' => 'pending']);

        $this->actingAsPlace($this->place);
        $ids = fn (array $query) => $this->idsOf('/api/v1/restaurant/orders', $query);

        $this->assertSame([$cooking->id, $new->id], $ids(['filter' => ['queue' => 'new,preparing'], 'sort' => '-total']));
        $this->assertSame([$cooking->id], $ids(['filter' => ['payment_method' => 'instapay']]));
        $this->assertSame([$cooking->id], $ids(['filter' => ['overdue' => '1']]));
        $this->assertSame([$guest->id], $ids(['filter' => ['origin' => 'direct']]));
        $this->assertSame([$guest->id], $ids(['filter' => ['q' => '077778']]));
        $this->assertSame([$guest->id], $ids(['filter' => ['search' => '077778']]));
        $this->assertSame([$new->id, $guest->id, $cooking->id], $ids(['sort' => 'total']));

        // An unknown queue used to be ignored — the whole board came back under a filter.
        $this->getJson('/api/v1/restaurant/orders?filter[queue]=kitchen')->assertStatus(422)->assertJsonStructure(['errors' => ['filter.queue']]);
    }

    public function test_the_place_board_date_range_is_a_cairo_day(): void
    {
        Carbon::setTestNow('2026-09-18 12:00:00');
        $early = $this->orderFor($this->place, ['created_at' => '2026-09-17 22:30:00']);
        $this->orderFor($this->place, ['created_at' => '2026-09-17 20:00:00']);

        $this->actingAsPlace($this->place);

        $this->assertSame([$early->id], $this->idsOf('/api/v1/restaurant/orders', ['filter' => ['date_from' => '2026-09-18']]));
        Carbon::setTestNow();
    }

    public function test_the_places_direct_orders_menu_and_categories(): void
    {
        $today = $this->orderFor($this->place, ['origin' => 'restaurant_direct', 'business_date' => '2026-09-18', 'guest_customer_name' => 'إسلام']);
        $this->orderFor($this->place, ['origin' => 'restaurant_direct', 'business_date' => '2026-09-10', 'guest_customer_name' => 'كريم']);
        $shrimp = $this->makeProduct($this->place, 'سندوتش جمبري', 60);
        $rice = $this->makeProduct($this->place, 'أرز', 40);
        $rice->update(['is_available' => false]);
        $hidden = $this->place->menuCategories()->create(['name_ar' => 'موسمي', 'name_en' => 'Seasonal', 'is_active' => false]);

        $this->actingAsPlace($this->place);

        $this->assertSame([$today->id], $this->idsOf('/api/v1/restaurant/direct-orders', ['filter' => ['date_from' => '2026-09-15', 'date_to' => '2026-09-20']]));
        $this->assertSame([$today->id], $this->idsOf('/api/v1/restaurant/direct-orders', ['filter' => ['search' => 'اسلام']]));
        $this->assertSame([$rice->id], $this->idsOf('/api/v1/restaurant/products', ['filter' => ['is_available' => '0']]));
        $this->assertSame([$shrimp->id, $rice->id], $this->idsOf('/api/v1/restaurant/products', ['sort' => '-price']));
        $this->assertSame([$hidden->id], $this->idsOf('/api/v1/restaurant/categories', ['filter' => ['is_active' => '0']]));
    }

    public function test_the_places_complaints_ratings_statements_and_scheduled_orders(): void
    {
        $order = $this->orderFor($this->place);
        $unread = $this->complaintAbout($order, ['ticket_number' => 'CMP-UNREAD']);
        $read = $this->complaintAbout($order, ['ticket_number' => 'CMP-READ', 'restaurant_first_read_at' => now(), 'category' => 'missing_item']);
        $five = $this->place->ratings()->create(['order_id' => $order->id, 'user_id' => $order->user_id, 'stars' => 5, 'comment' => 'ممتاز']);
        $two = $this->place->ratings()->create(['order_id' => $this->orderFor($this->place)->id, 'user_id' => $order->user_id, 'stars' => 2]);
        $paid = Settlement::query()->create(['party_type' => 'restaurant', 'party_id' => $this->place->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-07', 'status' => 'paid']);
        Settlement::query()->create(['party_type' => 'restaurant', 'party_id' => $this->place->id, 'period_start' => '2026-09-08', 'period_end' => '2026-09-14', 'status' => 'pending']);
        $customer = $this->customerNamed('منى', '01022220002', $this->city);
        $soon = $this->scheduledAt($customer, now()->addDay());
        $this->scheduledAt($customer, now()->addDays(6));

        $this->actingAsPlace($this->place);

        $this->assertSame([$unread->id], $this->idsOf('/api/v1/restaurant/complaints', ['filter' => ['unread' => '1']]));
        $this->assertSame([$read->id], $this->idsOf('/api/v1/restaurant/complaints', ['filter' => ['search' => 'CMP-READ']]));
        $this->assertSame([$read->id], $this->idsOf('/api/v1/restaurant/complaints', ['filter' => ['category' => 'missing_item']]));
        $this->assertSame([$two->id], $this->idsOf('/api/v1/restaurant/ratings', ['filter' => ['stars' => '1,2']]));
        $this->assertSame([$five->id], $this->idsOf('/api/v1/restaurant/ratings', ['filter' => ['has_comment' => '1']]));
        $this->assertSame([$paid->id], $this->idsOf('/api/v1/restaurant/settlements', ['filter' => ['status' => 'paid']]));
        $this->assertSame([$paid->id], $this->idsOf('/api/v1/restaurant/settlements', ['filter' => ['date_to' => '2026-09-05']]));
        $this->assertSame([$soon->id], $this->idsOf('/api/v1/restaurant/scheduled-orders', ['filter' => ['date_to' => now()->addDays(2)->toDateString()]]));
    }

    public function test_notifications_by_type_and_read_state(): void
    {
        $owner = $this->place->owner;
        $new = Notification::query()->create(['user_id' => $owner->id, 'type' => 'order.new', 'title_key' => 'order_new', 'payload' => []]);
        $reply = Notification::query()->create(['user_id' => $owner->id, 'type' => 'complaint.reply', 'title_key' => 'complaint_reply', 'payload' => [], 'read_at' => now()]);

        $this->actingAsPlace($this->place);

        $this->assertSame([$reply->id], $this->idsOf('/api/v1/restaurant/notifications', ['filter' => ['type' => 'complaint.reply']]));
        $this->assertSame([$new->id], $this->idsOf('/api/v1/restaurant/notifications', ['filter' => ['unread' => '1']]));
    }

    // ---------------------------------------------------------------- captain

    public function test_the_captains_history_statements_errands_and_earnings(): void
    {
        $captain = $this->captainNamed('كابتن رامي', '01011110001');
        $old = $this->orderFor($this->place, ['driver_id' => $captain->id, 'delivered_at' => now()->subDays(10), 'order_number' => 'CR7-3001-1', 'driver_earning' => 20]);
        $recent = $this->orderFor($this->place, ['driver_id' => $captain->id, 'delivered_at' => now()->subHour(), 'order_number' => 'CR7-3002-1', 'payment_method' => 'instapay', 'driver_earning' => 35]);
        $paid = Settlement::query()->create(['party_type' => 'driver', 'party_id' => $captain->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-07', 'status' => 'paid']);
        Settlement::query()->create(['party_type' => 'driver', 'party_id' => $captain->id, 'period_start' => '2026-09-08', 'period_end' => '2026-09-14', 'status' => 'pending']);
        $customer = $this->customerNamed('منى', '01022220002');
        $delivered = $this->errandFor($customer, ['driver_id' => $captain->id, 'status' => 'delivered']);
        $this->errandFor($customer, ['driver_id' => $captain->id, 'status' => 'assigned']);
        $captain->transactions()->create(['type' => 'order_earning', 'amount' => 35, 'balance_after' => 35]);
        $captain->transactions()->create(['type' => 'cash_collected', 'amount' => 120, 'balance_after' => 35]);

        $this->actingAsCaptainUser($captain);

        $this->assertSame([$recent->id], $this->idsOf('/api/v1/driver/orders', ['filter' => ['date_from' => now()->subDays(2)->toDateString()]]));
        $this->assertSame([$old->id], $this->idsOf('/api/v1/driver/orders', ['filter' => ['search' => '3001']]));
        $this->assertSame([$recent->id], $this->idsOf('/api/v1/driver/orders', ['filter' => ['payment_method' => 'instapay']]));
        $this->assertSame([$old->id, $recent->id], $this->idsOf('/api/v1/driver/orders', ['sort' => 'driver_earning']));
        $this->assertSame([$paid->id], $this->idsOf('/api/v1/driver/settlements', ['filter' => ['status' => 'paid']]));
        $this->assertSame([$delivered->id], $this->idsOf('/api/v1/driver/errands/mine', ['filter' => ['status' => 'delivered']]));

        $earnings = $this->list('/api/v1/driver/earnings', ['filter' => ['type' => 'cash_collected']])->json('data');
        $this->assertCount(1, $earnings['breakdown']);
        // The sums stay the window's: a chip narrows the rows, not the balances.
        $this->assertEquals(35, $earnings['total_earned']);
        $this->getJson('/api/v1/driver/earnings?from=yesterday')->assertStatus(422);
    }

    // --------------------------------------------------------------- customer

    public function test_the_customers_orders_complaints_errands_and_scheduled_orders(): void
    {
        $customer = $this->customerNamed('منى', '01022220002', $this->city);
        $kfc = $this->makeRestaurant('كنج بروست');
        $mine = $this->orderFor($this->place, ['order_number' => 'CR7-8801-1', 'status' => 'preparing'], $customer);
        $other = $this->orderFor($kfc, ['order_number' => 'CR7-8802-1'], $customer);
        $open = $this->complaintAbout($mine, ['user_id' => $customer->id]);
        $this->complaintAbout($other, ['user_id' => $customer->id, 'status' => 'resolved']);
        $cancelled = $this->errandFor($customer, ['status' => 'cancelled']);
        $this->errandFor($customer, ['status' => 'open']);
        $released = $this->scheduledAt($customer, now()->addDay(), ScheduledOrder::STATUS_RELEASED);
        $failed = $this->scheduledAt($customer, now()->addDays(2), ScheduledOrder::STATUS_FAILED);
        $this->scheduledAt($customer, now()->addDays(3));

        $this->actingAsCustomer($customer);

        $this->assertSame([$other->order_group_id], $this->idsOf('/api/v1/orders', ['filter' => ['search' => 'بروست']]));
        $this->assertSame([$mine->order_group_id], $this->idsOf('/api/v1/orders', ['filter' => ['search' => 'CR7-8801']]));
        $this->assertSame([$mine->order_group_id], $this->idsOf('/api/v1/orders', ['filter' => ['status_group' => 'active']]));
        $this->assertSame([$open->id], $this->idsOf('/api/v1/complaints', ['filter' => ['status' => 'open,in_progress']]));
        $this->assertSame([$cancelled->id], $this->idsOf('/api/v1/customer/errands', ['filter' => ['status' => 'cancelled']]));
        $this->assertSame([$released->id, $failed->id], $this->idsOf('/api/v1/scheduled-orders', ['filter' => ['status' => 'released,failed']]));
    }

    public function test_the_catalogue_counts_only_open_places_when_asked_for_open_ones(): void
    {
        $closed = $this->makeRestaurant('كنج بروست');
        $closed->update(['is_open_manual' => false]);
        $this->makeRestaurant('بوكس ع السريع');

        // Before batch 68 the closed place was cut from the page after paging: two rows
        // under a `total` of three, and `last_page` counting a page that would come back
        // empty.
        $this->getJson('/api/v1/restaurants?filter[open_now]=1&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data');

        $this->assertCount(1, $this->list('/api/v1/restaurants', ['filter' => ['search' => 'بروست']])->json('data'));
    }

    public function test_a_places_reviews_by_stars(): void
    {
        $order = $this->orderFor($this->place);
        $low = $this->place->ratings()->create(['order_id' => $order->id, 'user_id' => $order->user_id, 'stars' => 1]);
        $this->place->ratings()->create(['order_id' => $this->orderFor($this->place)->id, 'user_id' => $order->user_id, 'stars' => 5]);

        $this->assertSame([$low->id], $this->idsOf("/api/v1/restaurants/{$this->place->id}/reviews", ['filter' => ['stars' => '1']]));
    }

    private function scheduledAt(User $customer, Carbon $when, string $status = ScheduledOrder::STATUS_SCHEDULED): ScheduledOrder
    {
        $row = ScheduledOrder::query()->create([
            'reference' => 'SCH-'.uniqid(),
            'user_id' => $customer->id,
            'address_id' => $customer->default_address_id,
            'scheduled_for' => $when,
            'status' => $status,
            'payload' => [],
            'basket_snapshot' => [],
        ]);
        $row->restaurants()->attach($this->place->id);

        return $row;
    }

    private function errandFor(User $customer, array $attributes = []): ErrandRequest
    {
        return ErrandRequest::query()->create($attributes + [
            'reference' => 'ERR-'.uniqid(),
            'user_id' => $customer->id,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'status' => 'open',
            'pickup_lat' => 31.38, 'pickup_lng' => 31.48, 'pickup_address_text' => 'صيدلية',
            'dropoff_lat' => 31.39, 'dropoff_lng' => 31.49, 'dropoff_address_text' => 'البيت',
            'details' => 'دواء', 'offered_fee' => 30,
        ]);
    }
}
