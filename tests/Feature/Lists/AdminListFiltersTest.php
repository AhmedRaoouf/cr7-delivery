<?php

namespace Tests\Feature\Lists;

use App\Models\AuditLog;
use App\Models\CaptainApplication;
use App\Models\Cuisine;
use App\Models\DeliveryIssue;
use App\Models\DeliveryPricingRule;
use App\Models\ErrandRequest;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\Refund;
use App\Models\Restaurant;
use App\Models\ScheduledOrder;
use App\Models\Settlement;
use App\Models\StoreCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * Batch 68 — ADMIN-LISTS.md §1, one test per admin list: the filters and sorts the
 * contract marks «(new)», each shown to narrow (and not merely to answer 200).
 */
class AdminListFiltersTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    private User $admin;

    private Restaurant $omar;

    private Restaurant $box;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();

        $this->omar = $this->makeRestaurant('سندوتش عمر');
        $this->box = $this->makeRestaurant('بوكس ع السريع');
        $this->admin = $this->globalAdmin();
        $this->actingAsAdmin($this->admin);
    }

    public function test_orders_by_group_origin_captain_customer_payment_and_overdue(): void
    {
        $captain = $this->captainNamed('كابتن رامي', '01011110001');
        $ahmed = $this->customerNamed('أحمد', '01022220001');

        $live = $this->orderFor($this->omar, ['status' => 'preparing', 'prep_due_at' => now()->subMinute(), 'driver_id' => null, 'payment_method' => 'instapay'], $ahmed);
        $done = $this->orderFor($this->omar, ['status' => 'delivered', 'driver_id' => $captain->id]);
        $direct = $this->orderFor($this->box, ['status' => 'cancelled', 'origin' => 'restaurant_direct']);
        $issue = $this->orderFor($this->box, ['status' => 'delivery_issue', 'driver_id' => $captain->id, 'payment_method' => 'vodafone_cash']);

        $ids = fn (array $filter) => $this->sorted($this->idsOf('/api/v1/admin/orders', ['filter' => $filter]));

        // «active» is anything not over — an order with a delivery issue is still open.
        $this->assertSame([$live->id, $issue->id], $ids(['status_group' => 'active']));
        $this->assertSame($this->sorted([$direct->id, $issue->id]), $ids(['status_group' => 'cancelled,issue']));
        $this->assertSame([$direct->id], $ids(['origin' => 'direct']));
        $this->assertSame([$live->id, $direct->id], $ids(['driver_id' => 'none']));
        $this->assertSame([$done->id, $issue->id], $ids(['has_driver' => '1']));
        $this->assertSame([$live->id], $ids(['customer_id' => $ahmed->id]));
        $this->assertSame([$live->id, $issue->id], $ids(['payment_method' => 'instapay,vodafone_cash']));
        $this->assertSame([$live->id], $ids(['overdue' => 'true']));
        $this->assertSame([$direct->id, $issue->id], $ids(['restaurant_id' => (string) $this->box->id]));
        $this->assertSame([$done->id, $issue->id], $ids(['driver_id' => (string) $captain->id]));
    }

    /** The mobile place form fills its zone picker from this list — a city without its zones left it empty. */
    public function test_the_cities_list_carries_each_cities_zones(): void
    {
        $zone = \App\Models\ServiceZone::create([
            'city_id' => $this->city->id, 'name_ar' => 'وسط جمصة', 'name_en' => 'Gamasa Center',
            'center_lat' => 31.3833, 'center_lng' => 31.4833, 'radius_km' => 3,
            'delivery_fee' => 10, 'min_order_value' => 50, 'avg_delivery_minutes' => 30, 'is_active' => true,
        ]);

        $row = collect($this->getJson('/api/v1/admin/cities')->assertOk()->json('data'))
            ->firstWhere('id', $this->city->id);

        $this->assertContains($zone->id, array_column($row['service_zones'], 'id'));
    }

    public function test_the_orders_download_honours_the_sort(): void
    {
        $this->orderFor($this->omar, ['total' => 90, 'restaurant_order_ref' => 'R-LOW']);
        $this->orderFor($this->omar, ['total' => 900, 'restaurant_order_ref' => 'R-HIGH']);

        $sheet = fn (string $sort) => $this->sheetText($this->get('/api/v1/admin/orders/export?sort='.$sort)->assertOk()->streamedContent());

        $descending = $sheet('-total');
        $this->assertLessThan(strpos($descending, 'R-LOW'), strpos($descending, 'R-HIGH'));

        $ascending = $sheet('total');
        $this->assertLessThan(strpos($ascending, 'R-HIGH'), strpos($ascending, 'R-LOW'));
    }

    /** openspout writes inline strings, so the words are in the sheet itself. */
    private function sheetText(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $body);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $text = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with((string) $zip->getNameIndex($i), 'xl/worksheets/')) {
                $text .= (string) $zip->getFromIndex($i);
            }
        }

        $zip->close();
        unlink($path);

        return $text;
    }

    public function test_direct_orders_by_zone_captain_date_and_guest_phone(): void
    {
        $captain = $this->captainNamed('كابتن رامي', '01011110001');
        $here = $this->orderFor($this->omar, ['origin' => 'restaurant_direct', 'driver_id' => $captain->id, 'guest_customer_phone' => '01055556666']);
        $there = $this->orderFor($this->moveToOtherCity($this->box), ['origin' => 'restaurant_direct']);
        $this->orderFor($this->omar);

        $this->assertSame([$here->id], $this->idsOf('/api/v1/admin/direct-orders', ['filter' => ['driver_id' => $captain->id]]));
        $this->assertSame([$there->id], $this->idsOf('/api/v1/admin/direct-orders', ['filter' => ['zone_id' => $this->otherZone->id]]));
        $this->assertSame([$here->id], $this->idsOf('/api/v1/admin/direct-orders', ['filter' => ['search' => '055556']]));
        $this->assertSame([], $this->idsOf('/api/v1/admin/direct-orders', ['filter' => ['date_from' => now()->addDays(2)->toDateString()]]));
    }

    public function test_restaurants_by_zone_category_cuisine_flags_open_now_and_pending(): void
    {
        $cafes = StoreCategory::query()->create(['slug' => 'cafes', 'name_ar' => 'كافيهات', 'name_en' => 'Cafes', 'is_active' => true]);
        $grill = Cuisine::query()->create(['name_ar' => 'مشويات', 'name_en' => 'Grill', 'is_active' => true]);
        $this->box->update(['store_category_id' => $cafes->id, 'is_featured' => true, 'price_band' => 'premium', 'is_open_manual' => false]);
        $this->box->cuisines()->attach($grill->id);
        $this->omar->servedZones()->attach($this->otherZone->id);
        $this->orderFor($this->omar, ['status' => 'pending']);

        $ids = fn (array $filter) => $this->sorted($this->idsOf('/api/v1/admin/restaurants', ['filter' => $filter]));

        $this->assertSame([$this->omar->id], $ids(['zone_id' => $this->otherZone->id]));
        $this->assertSame([$this->box->id], $ids(['store_category_id' => $cafes->id]));
        $this->assertSame([$this->box->id], $ids(['cuisine_id' => $grill->id]));
        $this->assertSame([$this->box->id], $ids(['is_featured' => '1']));
        $this->assertSame([$this->box->id], $ids(['price_band' => 'premium']));
        $this->assertSame([$this->omar->id], $ids(['open_now' => '1']));
        $this->assertSame([$this->box->id], $ids(['open_now' => '0']));
        $this->assertSame([$this->omar->id], $ids(['has_pending' => '1']));
        // Folded: «بوكس ع السريع» from «السريع».
        $this->assertSame([$this->box->id], $ids(['search' => 'السريع']));

        $this->assertSame([$this->box->id, $this->omar->id], $this->idsOf('/api/v1/admin/restaurants', ['sort' => 'name']));
    }

    public function test_restaurants_open_now_follows_todays_hours_on_the_cairo_clock(): void
    {
        // Friday 2026-09-18 23:30 in Cairo (UTC+3) — 20:30 UTC.
        Carbon::setTestNow(Carbon::parse('2026-09-18 20:30:00', 'UTC'));
        $friday = Carbon::parse('2026-09-18')->dayOfWeek;
        $this->omar->update(['is_24_7' => false]);
        $this->box->update(['is_24_7' => false]);

        // A late shift that closes at 02:00 is open at 23:30; a day shift is not.
        $this->omar->openingHours()->create(['weekday' => $friday, 'opens_at' => '18:00:00', 'closes_at' => '02:00:00']);
        $this->box->openingHours()->create(['weekday' => $friday, 'opens_at' => '09:00:00', 'closes_at' => '17:00:00']);

        $this->assertSame([$this->omar->id], $this->idsOf('/api/v1/admin/restaurants', ['filter' => ['open_now' => '1']]));
        $this->assertTrue($this->omar->refresh()->isOpenNow());
        $this->assertFalse($this->box->refresh()->isOpenNow());

        Carbon::setTestNow();
    }

    public function test_drivers_by_zone_trip_staleness_vehicle_and_plate(): void
    {
        $rami = $this->captainNamed('كابتن رامي', '01011110001', null, ['is_online' => true, 'location_updated_at' => now()->subMinutes(5), 'vehicle_plate' => 'ط ر ٤٥٦']);
        $sami = $this->captainNamed('كابتن سامي', '01011110002', $this->otherZone, ['is_online' => true, 'location_updated_at' => now(), 'vehicle_type' => 'car']);
        $this->orderFor($this->omar, ['status' => 'picked_up', 'driver_id' => $sami->id]);

        $ids = fn (array $filter) => $this->idsOf('/api/v1/admin/drivers', ['filter' => $filter]);

        $this->assertSame([$sami->id], $ids(['zone_id' => $this->otherZone->id]));
        $this->assertSame([$sami->id], $ids(['has_active_order' => '1']));
        $this->assertSame([$rami->id], $ids(['has_active_order' => '0']));
        $this->assertSame([$rami->id], $ids(['stale' => '1']));
        $this->assertSame([$sami->id], $ids(['vehicle_type' => 'car']));
        $this->assertSame([$rami->id], $ids(['search' => 'ر ٤٥٦']));
        $this->assertSame([$sami->id, $rami->id], $this->idsOf('/api/v1/admin/drivers', ['sort' => '-name']));
    }

    public function test_customers_by_place_state_orders_and_email(): void
    {
        $here = $this->customerNamed('أحمد', '01022220001', $this->city);
        $there = $this->customerNamed('منى', '01022220002', $this->otherCity);
        $blocked = $this->customerNamed('يوسف', '01022220003');
        $blocked->update(['blocked_at' => now(), 'is_active' => false, 'email' => 'yousef@example.com']);
        $there->forceFill(['cash_only_until' => now()->addDay()])->save();
        $this->orderFor($this->omar, [], $here);
        $this->orderFor($this->omar, [], $here);

        $ids = fn (array $filter) => $this->sorted($this->idsOf('/api/v1/admin/customers', ['filter' => $filter]));

        $this->assertSame([$there->id], $ids(['city_id' => $this->otherCity->id]));
        $this->assertSame([$blocked->id], $ids(['blocked' => 'yes']));
        $this->assertSame([$there->id], $ids(['restricted' => '1']));
        $this->assertSame([$here->id], $ids(['has_orders' => '1']));
        $this->assertSame([$blocked->id], $ids(['search' => 'YOUSEF@example']));

        $rows = $this->list('/api/v1/admin/customers', ['sort' => '-orders_count'])->json('data');
        $this->assertSame($here->id, $rows[0]['id']);
        $this->assertSame(2, $rows[0]['orders_count']);
    }

    public function test_customer_orders_by_status_group(): void
    {
        $customer = $this->customerNamed('أحمد', '01022220001');
        $open = $this->orderFor($this->omar, ['status' => 'preparing'], $customer);
        $this->orderFor($this->omar, ['status' => 'delivered'], $customer);

        $this->assertSame([$open->order_group_id], $this->idsOf("/api/v1/admin/customers/{$customer->id}/orders", ['filter' => ['status_group' => 'active']]));
    }

    public function test_complaints_by_category_priority_role_assignee_place_and_sort(): void
    {
        $order = $this->orderFor($this->omar);
        $other = $this->orderFor($this->box);
        $mine = $this->complaintAbout($order, ['assigned_to' => $this->admin->id, 'priority' => 'low', 'category' => 'late_delivery']);
        $urgent = $this->complaintAbout($other, ['priority' => 'high', 'user_role' => 'restaurant', 'due_at' => now()->subHour()]);

        $ids = fn (array $filter) => $this->idsOf('/api/v1/admin/complaints', ['filter' => $filter]);

        $this->assertSame([$mine->id], $ids(['category' => 'late_delivery,missing_item']));
        $this->assertSame([$urgent->id], $ids(['priority' => 'high']));
        $this->assertSame([$urgent->id], $ids(['user_role' => 'restaurant']));
        $this->assertSame([$mine->id], $ids(['assigned_to' => 'me']));
        $this->assertSame([$urgent->id], $ids(['assigned_to' => 'none']));
        $this->assertSame([$urgent->id], $ids(['restaurant_id' => $this->box->id]));
        $this->assertSame([$urgent->id], $ids(['overdue' => '1']));
        $this->assertSame([$mine->id], $ids(['overdue' => '0']));
        $this->assertSame([$mine->id], $ids(['search' => $mine->ticket_number]));
        $this->assertSame([$urgent->id, $mine->id], $this->idsOf('/api/v1/admin/complaints', ['sort' => '-priority']));
    }

    public function test_payments_by_method_overdue_and_basket_number(): void
    {
        $wallet = $this->orderFor($this->omar, ['payment_method' => 'vodafone_cash', 'order_number' => 'CR7-4411-1']);
        $this->orderFor($this->omar);
        $payment = $wallet->orderGroup->payment;
        $payment->update(['status' => 'awaiting_proof', 'deadline_at' => now()->subMinute()]);

        $ids = fn (array $filter) => $this->idsOf('/api/v1/admin/payments', ['filter' => $filter]);

        $this->assertSame([$payment->id], $ids(['method' => 'vodafone_cash']));
        $this->assertSame([$payment->id], $ids(['overdue' => '1']));
        $this->assertSame([$payment->id], $ids(['search' => 'CR7-4411']));
        $this->assertSame([$payment->id], $ids(['status' => 'awaiting_proof,under_review']));
    }

    public function test_refunds_by_type_place_and_order_number(): void
    {
        $a = $this->orderFor($this->omar, ['order_number' => 'CR7-6001-1']);
        $b = $this->orderFor($this->box);
        $full = Refund::query()->create(['order_id' => $a->id, 'payment_id' => $a->orderGroup->payment->id, 'amount' => 120, 'type' => 'full', 'reason_code' => 'late', 'status' => 'pending']);
        $part = Refund::query()->create(['order_id' => $b->id, 'payment_id' => $b->orderGroup->payment->id, 'amount' => 10, 'type' => 'partial', 'reason_code' => 'cold', 'status' => 'processed']);

        $ids = fn (array $filter) => $this->idsOf('/api/v1/admin/refunds', ['filter' => $filter]);

        $this->assertSame([$full->id], $ids(['type' => 'full']));
        $this->assertSame([$part->id], $ids(['restaurant_id' => $this->box->id]));
        $this->assertSame([$full->id], $ids(['search' => '6001']));
        $this->assertSame([$part->id], $ids(['reason_code' => 'cold']));
        $this->assertSame([$full->id, $part->id], $this->idsOf('/api/v1/admin/refunds', ['sort' => '-amount']));
    }

    public function test_settlements_are_named_searched_and_ranged_by_party_and_period(): void
    {
        $captain = $this->captainNamed('كابتن رامي', '01011110001');
        $place = Settlement::query()->create(['party_type' => 'restaurant', 'party_id' => $this->omar->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-07', 'status' => 'pending']);
        $driver = Settlement::query()->create(['party_type' => 'driver', 'party_id' => $captain->id, 'period_start' => '2026-09-08', 'period_end' => '2026-09-14', 'status' => 'paid']);

        $rows = collect($this->list('/api/v1/admin/settlements')->json('data'))->keyBy('id');
        $this->assertSame('سندوتش عمر', $rows[$place->id]['party_name']);
        // A captain's statement used to be named nothing: the name lives on `users`.
        $this->assertSame('كابتن رامي', $rows[$driver->id]['party_name']);

        $ids = fn (array $filter) => $this->idsOf('/api/v1/admin/settlements', ['filter' => $filter]);

        $this->assertSame([$place->id], $ids(['restaurant_id' => $this->omar->id]));
        $this->assertSame([$driver->id], $ids(['driver_id' => $captain->id]));
        $this->assertSame([$driver->id], $ids(['search' => 'رامي']));
        $this->assertSame([$place->id], $ids(['search' => 'عمر']));
        $this->assertSame([$place->id], $ids(['date_from' => '2026-09-03', 'date_to' => '2026-09-05']));
        $this->assertSame([$driver->id], $ids(['status' => 'paid']));
    }

    public function test_delivery_issues_by_category_reporter_and_captain(): void
    {
        $captain = $this->captainNamed('كابتن رامي', '01011110001');
        $a = $this->orderFor($this->omar, ['driver_id' => $captain->id]);
        $b = $this->orderFor($this->box);
        $accident = DeliveryIssue::query()->create(['order_id' => $a->id, 'reported_by' => 'driver', 'category' => 'accident', 'status' => 'open']);
        $address = DeliveryIssue::query()->create(['order_id' => $b->id, 'reported_by' => 'customer', 'category' => 'wrong_address', 'status' => 'open']);

        $ids = fn (array $filter) => $this->idsOf('/api/v1/admin/delivery-issues', ['filter' => $filter]);

        $this->assertSame([$accident->id], $ids(['category' => 'accident']));
        $this->assertSame([$address->id], $ids(['reported_by' => 'customer']));
        $this->assertSame([$accident->id], $ids(['driver_id' => $captain->id]));
    }

    public function test_captain_applications_by_folded_name_vehicle_and_status_list(): void
    {
        $a = CaptainApplication::query()->create(['reference' => 'CAP-1', 'full_name' => 'إبراهيم مصطفى', 'phone' => '01066660001', 'vehicle_type' => 'car', 'status' => 'pending']);
        $b = CaptainApplication::query()->create(['reference' => 'CAP-2', 'full_name' => 'علي حسن', 'phone' => '01066660002', 'vehicle_type' => 'motorcycle', 'status' => 'rejected']);

        $ids = fn (array $filter) => $this->idsOf('/api/v1/admin/captain-applications', ['filter' => $filter]);

        $this->assertSame([$a->id], $ids(['search' => 'ابراهيم']));
        $this->assertSame([$a->id], $ids(['vehicle_type' => 'car']));
        $this->assertSame([$b->id], $ids(['status' => 'approved,rejected']));
    }

    public function test_errands_by_customer_captain_and_zone(): void
    {
        $captain = $this->captainNamed('كابتن رامي', '01011110001');
        $mona = $this->customerNamed('منى', '01022220002');
        $taken = $this->errand('ERR-1', $mona->id, ['driver_id' => $captain->id, 'status' => 'assigned']);
        $open = $this->errand('ERR-2', $this->customerNamed('أحمد', '01022220001')->id, ['service_zone_id' => $this->otherZone->id, 'city_id' => $this->otherCity->id]);

        $ids = fn (array $filter) => $this->idsOf('/api/v1/admin/errands', ['filter' => $filter]);

        $this->assertSame([$taken->id], $ids(['search' => 'منى']));
        $this->assertSame([$taken->id], $ids(['driver_id' => $captain->id]));
        $this->assertSame([$open->id], $ids(['zone_id' => $this->otherZone->id]));
        $this->assertSame([$open->id], $ids(['unclaimed' => '1']));
    }

    public function test_scheduled_orders_by_customer_and_day(): void
    {
        $mona = $this->customerNamed('منى', '01022220002', $this->city);
        $soon = $this->scheduled('SCH-1', $mona, now()->addDay());
        $later = $this->scheduled('SCH-2', $this->customerNamed('أحمد', '01022220001', $this->city), now()->addDays(5));

        $this->assertSame([$soon->id], $this->idsOf('/api/v1/admin/scheduled-orders', ['filter' => ['customer_id' => $mona->id]]));
        $this->assertSame([$later->id], $this->idsOf('/api/v1/admin/scheduled-orders', ['filter' => ['date_from' => now()->addDays(4)->toDateString()]]));
        $this->assertSame([$soon->id], $this->idsOf('/api/v1/admin/scheduled-orders', ['filter' => ['search' => 'منى']]));
        $this->assertSame([$later->id, $soon->id], $this->idsOf('/api/v1/admin/scheduled-orders', ['sort' => '-scheduled_for']));
    }

    public function test_promo_codes_by_state_type_and_title(): void
    {
        $live = PromoCode::query()->create(['code' => 'WELCOME', 'title_ar' => 'خصم الترحيب', 'title_en' => 'Welcome', 'type' => 'percent', 'value' => 10, 'is_active' => true]);
        $expired = PromoCode::query()->create(['code' => 'SUMMER', 'title_ar' => 'الصيف', 'title_en' => 'Summer', 'type' => 'fixed', 'value' => 20, 'is_active' => true, 'ends_at' => now()->subDay()]);
        $soon = PromoCode::query()->create(['code' => 'EID', 'title_ar' => 'العيد', 'title_en' => 'Eid', 'type' => 'free_delivery', 'value' => 0, 'is_active' => true, 'starts_at' => now()->addDay()]);

        $ids = fn (array $filter) => $this->idsOf('/api/v1/admin/promo-codes', ['filter' => $filter]);

        $this->assertSame([$live->id], $ids(['state' => 'live']));
        $this->assertSame([$expired->id], $ids(['state' => 'expired']));
        $this->assertSame([$soon->id], $ids(['state' => 'scheduled']));
        $this->assertSame([$soon->id], $ids(['type' => 'free_delivery']));
        $this->assertSame([$live->id], $ids(['search' => 'الترحيب']));
        $this->assertSame([$expired->id], $ids(['search' => 'summ']));
    }

    public function test_promo_usages_by_customer(): void
    {
        $promo = PromoCode::query()->create(['code' => 'WELCOME', 'title_ar' => 'خصم', 'title_en' => 'Save', 'type' => 'fixed', 'value' => 10, 'is_active' => true]);
        $mona = $this->customerNamed('منى', '01022220002');
        $a = $promo->usages()->create(['user_id' => $mona->id, 'order_id' => $this->orderFor($this->omar, [], $mona)->id, 'discount_amount' => 10]);
        $promo->usages()->create(['user_id' => ($b = $this->customerNamed('أحمد', '01022220001'))->id, 'order_id' => $this->orderFor($this->omar, [], $b)->id, 'discount_amount' => 10]);

        $this->assertSame([$a->id], $this->idsOf("/api/v1/admin/promo-codes/{$promo->id}/usages", ['filter' => ['search' => 'منى']]));
    }

    public function test_admin_users_by_status_and_email(): void
    {
        $invited = User::query()->create(['name' => 'سارة', 'phone' => '01000000077', 'email' => 'sara@cr7.test', 'type' => 'admin', 'is_active' => true]);
        $invited->adminProfile()->create(['invited_at' => now()]);
        $this->admin->adminProfile()->create(['accepted_at' => now()]);

        $this->assertSame([$invited->id], $this->idsOf('/api/v1/admin/users', ['filter' => ['search' => 'sara@']]));
        $this->assertSame([$invited->id], $this->idsOf('/api/v1/admin/users', ['filter' => ['status' => 'invited']]));
        $this->assertSame([$this->admin->id], $this->idsOf('/api/v1/admin/users', ['filter' => ['status' => 'active']]));
    }

    public function test_audit_logs_by_action_prefix_and_entity(): void
    {
        $order = $this->orderFor($this->omar);
        $cancel = AuditLog::query()->create(['user_id' => $this->admin->id, 'action' => 'orders.cancel', 'auditable_type' => Order::class, 'auditable_id' => $order->id]);
        $place = AuditLog::query()->create(['user_id' => $this->admin->id, 'action' => 'restaurants.update', 'auditable_type' => Restaurant::class, 'auditable_id' => $this->omar->id]);

        $ids = fn (array $filter) => $this->idsOf('/api/v1/admin/audit-logs', ['filter' => $filter]);

        $this->assertSame([$cancel->id], $ids(['action' => 'orders.*']));
        $this->assertSame([$place->id], $ids(['action' => 'restaurants.update']));
        $this->assertSame([$place->id], $ids(['auditable_type' => 'restaurant']));
        $this->assertSame([$cancel->id], $ids(['auditable_type' => 'order', 'auditable_id' => $order->id]));
    }

    public function test_cities_zones_and_pricing_rules(): void
    {
        $this->otherCity->update(['is_active' => false]);
        $this->otherZone->update(['is_selectable' => false]);
        $rule = DeliveryPricingRule::query()->create(['city_id' => $this->city->id, 'service_zone_id' => $this->zone->id, 'min_distance_km' => 0, 'max_distance_km' => 3, 'fee' => 15, 'is_active' => true]);
        DeliveryPricingRule::query()->create(['city_id' => $this->otherCity->id, 'service_zone_id' => $this->otherZone->id, 'min_distance_km' => 0, 'max_distance_km' => 3, 'fee' => 25, 'is_active' => false]);

        $this->assertSame([$this->otherCity->id], $this->idsOf('/api/v1/admin/cities', ['filter' => ['search' => 'المنصوره']]));
        $this->assertSame([$this->city->id], $this->idsOf('/api/v1/admin/cities', ['filter' => ['is_active' => '1']]));
        $this->assertSame([$this->otherZone->id], $this->idsOf('/api/v1/admin/zones', ['filter' => ['is_selectable' => '0']]));
        $this->assertSame([$this->zone->id], $this->idsOf('/api/v1/admin/zones', ['filter' => ['search' => 'وسط']]));
        $this->assertSame([$rule->id], $this->idsOf('/api/v1/admin/pricing-rules', ['filter' => ['is_active' => 'true']]));
        $this->assertSame([$rule->id], $this->idsOf('/api/v1/admin/pricing-rules', ['filter' => ['zone_id' => $this->zone->id]]));
    }

    public function test_a_places_menu_by_name_availability_and_price(): void
    {
        $shrimp = $this->makeProduct($this->omar, 'سندوتش جمبري', 60);
        $rice = $this->makeProduct($this->omar, 'أرز بالخلطة', 40);
        $rice->update(['is_available' => false]);

        $path = "/api/v1/admin/restaurants/{$this->omar->id}/products";

        $this->assertSame([$shrimp->id], $this->idsOf($path, ['filter' => ['search' => 'جمبري']]));
        $this->assertSame([$rice->id], $this->idsOf($path, ['filter' => ['is_available' => '0']]));
        $this->assertSame([$rice->id, $shrimp->id], $this->idsOf($path, ['sort' => 'price']));
    }

    public function test_the_fleets_money_by_debt_and_name(): void
    {
        $owes = $this->captainNamed('كابتن رامي', '01011110001');
        $owes->forceFill(['cash_in_hand' => 300, 'wallet_balance' => 50])->save();
        $owed = $this->captainNamed('كابتن سامي', '01011110002');
        $owed->forceFill(['cash_in_hand' => 0, 'wallet_balance' => 80])->save();

        $this->assertSame([$owes->id], $this->idsOf('/api/v1/admin/finance/drivers', ['filter' => ['owes' => '1']], 'driver_id'));
        $this->assertSame([$owed->id], $this->idsOf('/api/v1/admin/finance/drivers', ['filter' => ['owes' => '0']], 'driver_id'));
        $this->assertSame([$owes->id, $owed->id], $this->idsOf('/api/v1/admin/finance/drivers', ['sort' => 'name'], 'driver_id'));

        $this->list('/api/v1/admin/finance/drivers', ['filter' => ['owes' => '1']])
            // The tiles are the whole scope, not the filtered table.
            ->assertJsonPath('meta.totals.cash_in_hand', 300)
            ->assertJsonPath('meta.totals.wallet_balance', 130);
    }

    public function test_the_places_money_is_summed_by_the_database(): void
    {
        $this->orderFor($this->omar, ['payment_method' => 'instapay', 'subtotal' => 200, 'commission_amount' => 20, 'delivered_at' => now()]);
        $this->orderFor($this->omar, ['payment_method' => 'cash', 'subtotal' => 100, 'commission_amount' => 10, 'delivered_at' => now()]);

        $rows = collect($this->list('/api/v1/admin/finance/restaurants')->json('data'))->keyBy('restaurant_id');

        $this->assertEquals(180, $rows[$this->omar->id]['owed_online']);
        $this->assertSame(1, $rows[$this->omar->id]['online_orders']);
        $this->assertEquals(90, $rows[$this->omar->id]['paid_at_pickup']);
        $this->assertSame(1, $rows[$this->omar->id]['cash_orders']);
        $this->assertNotNull($rows[$this->omar->id]['oldest_unclaimed_at']);

        $this->assertSame([$this->omar->id], $this->idsOf('/api/v1/admin/finance/restaurants', ['filter' => ['owed' => '1']], 'restaurant_id'));
        $this->assertSame([$this->box->id], $this->idsOf('/api/v1/admin/finance/restaurants', ['filter' => ['search' => 'بوكس']], 'restaurant_id'));
    }

    public function test_a_captains_ledger_by_type_list_and_day(): void
    {
        $captain = $this->captainNamed('كابتن رامي', '01011110001');
        $earning = $captain->transactions()->create(['type' => 'order_earning', 'amount' => 15, 'balance_after' => 15]);
        $cash = $captain->transactions()->create(['type' => 'cash_collected', 'amount' => 120, 'balance_after' => 15]);
        $captain->transactions()->create(['type' => 'payout', 'amount' => -15, 'balance_after' => 0]);

        $ids = $this->sorted($this->idsOf("/api/v1/admin/drivers/{$captain->id}/ledger", ['filter' => ['type' => 'order_earning,cash_collected']]));
        $this->assertSame($this->sorted([$earning->id, $cash->id]), $ids);
        $this->assertSame([], $this->idsOf("/api/v1/admin/drivers/{$captain->id}/ledger", ['filter' => ['date_to' => now()->subDays(3)->toDateString()]]));
    }

    public function test_the_ranked_reports_search_and_narrow(): void
    {
        $this->makeProduct($this->omar, 'سندوتش جمبري', 60);
        $this->makeProduct($this->box, 'بوكس فراخ', 80);
        $captain = $this->captainNamed('كابتن رامي', '01011110001');
        $this->captainNamed('كابتن سامي', '01011110002', $this->otherZone);

        $this->assertSame([$this->box->id], $this->idsOf('/api/v1/admin/reports/restaurants', ['filter' => ['search' => 'بوكس']]));
        $this->assertCount(1, $this->idsOf('/api/v1/admin/reports/products', ['filter' => ['restaurant_id' => $this->omar->id]]));
        $this->assertSame([$captain->id], $this->idsOf('/api/v1/admin/reports/drivers', ['filter' => ['search' => 'رامي']]));
        $this->assertSame([$captain->id], $this->idsOf('/api/v1/admin/reports/drivers', ['filter' => ['city_id' => $this->city->id]]));
        $this->customerNamed('منى', '01022220002');
        $this->assertCount(1, $this->idsOf('/api/v1/admin/reports/customers', ['filter' => ['search' => 'منى']]));
        PromoCode::query()->create(['code' => 'WELCOME', 'title_ar' => 'خصم', 'title_en' => 'Save', 'type' => 'fixed', 'value' => 10, 'is_active' => false]);
        $this->assertCount(1, $this->idsOf('/api/v1/admin/reports/promo-codes', ['filter' => ['is_active' => '0']]));
    }

    public function test_lookups_are_light_sorted_scoped_and_capped(): void
    {
        $this->moveToOtherCity($this->box);

        $this->list('/api/v1/admin/lookups/restaurants')
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('meta.truncated', false)
            ->assertJsonPath('data.0.name', 'بوكس ع السريع')
            ->assertJsonStructure(['data' => [['id', 'name', 'name_en', 'city_id', 'is_active']]]);

        $this->list('/api/v1/admin/lookups/restaurants', ['limit' => 1])->assertJsonPath('meta.truncated', true)->assertJsonCount(1, 'data');
        $this->assertSame([$this->omar->id], $this->idsOf('/api/v1/admin/lookups/restaurants', ['filter' => ['city_id' => $this->city->id]]));
        $this->assertSame([$this->admin->id], $this->idsOf('/api/v1/admin/lookups/admins'));
        $this->getJson('/api/v1/admin/lookups/passwords')->assertNotFound();

        // A city admin's picker holds their own city's places.
        $this->actingAsAdmin($this->cityAdmin($this->otherCity));
        $this->assertSame([$this->box->id], $this->idsOf('/api/v1/admin/lookups/restaurants'));
    }

    /** @param  list<int>  $ids */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private function errand(string $reference, int $userId, array $attributes = []): ErrandRequest
    {
        return ErrandRequest::query()->create($attributes + [
            'reference' => $reference,
            'user_id' => $userId,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'status' => 'open',
            'pickup_lat' => 31.38, 'pickup_lng' => 31.48, 'pickup_address_text' => 'صيدلية',
            'dropoff_lat' => 31.39, 'dropoff_lng' => 31.49, 'dropoff_address_text' => 'البيت',
            'details' => 'دواء', 'offered_fee' => 30,
        ]);
    }

    private function scheduled(string $reference, User $customer, Carbon $when): ScheduledOrder
    {
        $row = ScheduledOrder::query()->create([
            'reference' => $reference,
            'user_id' => $customer->id,
            'address_id' => $customer->default_address_id,
            'scheduled_for' => $when,
            'status' => ScheduledOrder::STATUS_SCHEDULED,
            'payload' => [],
            'basket_snapshot' => [],
        ]);
        $row->restaurants()->attach($this->omar->id);

        return $row;
    }
}
