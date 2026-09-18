<?php

namespace Tests\Feature\Lists;

use App\Models\AuditLog;
use App\Models\CaptainApplication;
use App\Models\DeliveryIssue;
use App\Models\Driver;
use App\Models\ErrandRequest;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\Refund;
use App\Models\Restaurant;
use App\Models\ScheduledOrder;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * Batch 68 — «مهما كان عدد اليوزرز ميبقاش فيه ضغط علي السيرفر».
 *
 * A list that loads a relation per row costs one query per row on every poll, and the
 * board polls every fifteen seconds. Nothing in a response shows it — the rows are right,
 * the count is right — so this test turns lazy loading into an exception for its own
 * duration (never in production: a missed relation there should cost a query, not a
 * 500) and walks every list with at least two rows of everything each row reads.
 *
 * A failure here names the model and the relation that was loaded per row.
 */
class ListLazyLoadingTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    private Restaurant $place;

    private Restaurant $second;

    private Driver $captain;

    private User $customer;

    /** @var list<Order> */
    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();

        $this->place = $this->makeRestaurant('سندوتش عمر');
        $this->second = $this->makeRestaurant('كنج بروست');
        $this->makeProduct($this->place, 'سندوتش فول', 15);
        $this->makeProduct($this->place, 'سندوتش طعمية', 12);

        $this->captain = $this->captainNamed('كابتن رامي', '01011110001');
        $other = $this->captainNamed('كابتن سامي', '01011110002');
        $this->customer = $this->customerNamed('أحمد علي', '01022220001', $this->city);
        $second = $this->customerNamed('محمود حسن', '01022220002', $this->city);

        foreach ([[$this->place, $this->captain, $this->customer], [$this->second, $other, $second]] as $i => [$place, $captain, $customer]) {
            $order = $this->orderFor($place, ['driver_id' => $captain->id, 'order_number' => 'CR7-10'.$i.'0-1', 'delivered_at' => now()], $customer);
            $direct = $this->orderFor($place, ['origin' => 'restaurant_direct', 'status' => 'preparing', 'guest_customer_name' => 'ضيف', 'guest_customer_phone' => '0103333000'.$i, 'business_date' => now()->toDateString(), 'order_number' => 'CR7-20'.$i.'0-1'], $customer);
            $this->orders[] = $order;

            $this->complaintAbout($order, ['assigned_to' => null]);
            DeliveryIssue::query()->create(['order_id' => $order->id, 'reported_by' => 'driver', 'reporter_id' => $captain->user_id, 'category' => 'other', 'status' => 'open']);
            Refund::query()->create(['order_id' => $order->id, 'payment_id' => $order->orderGroup->payment->id, 'amount' => 10, 'type' => 'partial', 'reason_code' => 'other', 'status' => 'pending']);
            Settlement::query()->create(['party_type' => 'restaurant', 'party_id' => $place->id, 'period_start' => now()->subWeek()->toDateString(), 'period_end' => now()->toDateString(), 'status' => 'pending']);
            Settlement::query()->create(['party_type' => 'driver', 'party_id' => $captain->id, 'period_start' => now()->subWeek()->toDateString(), 'period_end' => now()->toDateString(), 'status' => 'pending']);
            $captain->transactions()->create(['order_id' => $order->id, 'type' => 'order_earning', 'amount' => 15, 'balance_after' => 15]);
            $place->ratings()->create(['order_id' => $order->id, 'user_id' => $customer->id, 'stars' => 4 + $i, 'comment' => 'ممتاز']);
            ErrandRequest::query()->create([
                'reference' => 'ERR-00'.$i, 'user_id' => $customer->id, 'city_id' => $this->city->id, 'service_zone_id' => $this->zone->id,
                'status' => 'assigned', 'driver_id' => $captain->id,
                'pickup_lat' => 31.38, 'pickup_lng' => 31.48, 'pickup_address_text' => 'صيدلية', 'dropoff_lat' => 31.39, 'dropoff_lng' => 31.49,
                'dropoff_address_text' => 'البيت', 'details' => 'دواء', 'offered_fee' => 30,
            ]);
            $scheduled = ScheduledOrder::query()->create([
                'reference' => 'SCH-00'.$i, 'user_id' => $customer->id, 'address_id' => $customer->default_address_id,
                'scheduled_for' => now()->addDay(), 'status' => ScheduledOrder::STATUS_SCHEDULED, 'payload' => [], 'basket_snapshot' => [],
            ]);
            $scheduled->restaurants()->attach($place->id);
            CaptainApplication::query()->create(['reference' => 'CAP-00'.$i, 'full_name' => 'متقدم '.$i, 'phone' => '0104444000'.$i, 'city_id' => $this->city->id, 'status' => 'pending']);
            $promo = PromoCode::query()->create(['code' => 'SAVE'.$i, 'title_ar' => 'خصم', 'title_en' => 'Save', 'type' => 'fixed', 'value' => 10, 'is_active' => true]);
            $promo->usages()->create(['user_id' => $customer->id, 'order_id' => $order->id, 'discount_amount' => 10]);
            AuditLog::query()->create(['user_id' => $customer->id, 'action' => 'orders.cancel', 'auditable_type' => Order::class, 'auditable_id' => $order->id]);

            foreach ([$customer, $captain->user, $place->owner] as $recipient) {
                Notification::query()->create(['user_id' => $recipient->id, 'type' => 'order.status', 'title_key' => 'order_delivered', 'payload' => [], 'order_id' => $order->id]);
            }
        }

        Model::preventLazyLoading();
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    public function test_no_admin_list_loads_a_relation_per_row(): void
    {
        $this->actingAsAdmin($this->globalAdmin());
        $promo = PromoCode::query()->firstOrFail();

        foreach ([
            '/api/v1/admin/orders',
            '/api/v1/admin/direct-orders',
            '/api/v1/admin/restaurants',
            '/api/v1/admin/drivers',
            '/api/v1/admin/customers',
            "/api/v1/admin/customers/{$this->customer->id}/orders",
            '/api/v1/admin/complaints',
            '/api/v1/admin/payments',
            '/api/v1/admin/refunds',
            '/api/v1/admin/settlements',
            '/api/v1/admin/delivery-issues',
            '/api/v1/admin/captain-applications',
            '/api/v1/admin/errands',
            '/api/v1/admin/scheduled-orders',
            '/api/v1/admin/promo-codes',
            "/api/v1/admin/promo-codes/{$promo->id}/usages",
            '/api/v1/admin/users',
            '/api/v1/admin/audit-logs',
            '/api/v1/admin/cities',
            '/api/v1/admin/zones',
            '/api/v1/admin/pricing-rules',
            "/api/v1/admin/restaurants/{$this->place->id}/products",
            '/api/v1/admin/finance/drivers',
            '/api/v1/admin/finance/restaurants',
            "/api/v1/admin/drivers/{$this->captain->id}/ledger",
            '/api/v1/admin/reports/customers',
            '/api/v1/admin/reports/drivers',
            '/api/v1/admin/reports/products',
            '/api/v1/admin/reports/restaurants',
            '/api/v1/admin/reports/promo-codes',
            '/api/v1/admin/lookups/restaurants',
            '/api/v1/admin/lookups/drivers',
            '/api/v1/admin/lookups/zones',
            '/api/v1/admin/lookups/admins',
        ] as $path) {
            $this->getJson($path)->assertOk();
        }

        $this->get('/api/v1/admin/orders/export')->assertOk()->streamedContent();
    }

    public function test_no_place_list_loads_a_relation_per_row(): void
    {
        $this->actingAsPlace($this->place);

        foreach ([
            '/api/v1/restaurant/orders',
            '/api/v1/restaurant/direct-orders',
            '/api/v1/restaurant/products',
            '/api/v1/restaurant/categories',
            '/api/v1/restaurant/complaints',
            '/api/v1/restaurant/ratings',
            '/api/v1/restaurant/settlements',
            '/api/v1/restaurant/scheduled-orders',
            '/api/v1/restaurant/notifications',
        ] as $path) {
            $this->getJson($path)->assertOk();
        }
    }

    public function test_no_captain_list_loads_a_relation_per_row(): void
    {
        $this->actingAsCaptainUser($this->captain);

        foreach ([
            '/api/v1/driver/orders',
            '/api/v1/driver/settlements',
            '/api/v1/driver/errands/mine',
            '/api/v1/driver/earnings',
            '/api/v1/notifications',
        ] as $path) {
            $this->getJson($path)->assertOk();
        }
    }

    public function test_no_customer_list_loads_a_relation_per_row(): void
    {
        $this->actingAsCustomer($this->customer);

        foreach ([
            '/api/v1/orders',
            '/api/v1/complaints',
            '/api/v1/customer/errands',
            '/api/v1/scheduled-orders',
            '/api/v1/notifications',
            '/api/v1/restaurants',
            "/api/v1/restaurants/{$this->place->id}/products",
            "/api/v1/restaurants/{$this->place->id}/reviews",
        ] as $path) {
            $this->getJson($path)->assertOk();
        }
    }
}
