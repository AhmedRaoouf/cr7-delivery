<?php

namespace Database\Seeders;

use App\Enums\AdminScopeType;
use App\Enums\OrderStatus;
use App\Enums\UserType;
use App\Models\CaptainApplication;
use App\Models\Complaint;
use App\Models\ComplaintMessage;
use App\Models\DeliveryIssue;
use App\Models\Driver;
use App\Models\DriverLocation;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\Rating;
use App\Models\Restaurant;
use App\Models\Settlement;
use App\Models\ServiceZone;
use App\Models\SettlementItem;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Demo traffic — customers, captains, orders in every board state, complaints,
 * ratings, applications and one settlement.
 *
 * It is NOT called by `DatabaseSeeder`. The catalogue seeders describe the real
 * business; this one invents people and orders, and inventing an order on a live
 * server means inventing a settlement line. Run it explicitly:
 *
 *     php artisan db:seed --class=DemoDataSeeder
 *
 * Every row is keyed on a natural key that starts with `DEMO`, so re-running is a
 * no-op and `php artisan db:seed --class=DemoDataSeeder` after a schema change
 * updates rather than duplicates. `demo:flush` removes exactly this set.
 *
 * The point of it is the dashboards: `RES-03`'s six queues, `ADM-02`'s tiles,
 * `ADM-56`'s fleet and `RES-30`/`ADM-40`'s two-sided box are all empty screens
 * against a freshly seeded catalogue, and an empty screen proves nothing.
 */
class DemoDataSeeder extends Seeder
{
    /** doc 17 — Gamasa. Every coordinate below sits inside the two service zones. */
    private const CITY_ID = 1;

    /** D-63 — 5 EGP per order group, snapshotted onto the sub-order. */
    private const PLATFORM_FEE = 5.0;

    /**
     * Not a constant: `service_zones.delivery_fee` is the configured number
     * (`ADM-24` edits it), and an order whose fee disagrees with the zone it was
     * delivered to is the contradiction an operator notices first.
     *
     * @var array<int, float>
     */
    private array $zoneFees = [];

    /**
     * `restaurant_order_ref` is unique per restaurant per business date (D-41 — the
     * POS number is how a kitchen finds the ticket, so two orders cannot share one).
     * A random three-digit number collides; this counts instead.
     *
     * @var array<string, int>
     */
    private array $posCounters = [];

    public function run(): void
    {
        if (app()->isProduction() && env('DEMO_SEED') !== true) {
            $this->command?->error('Refusing to run on production. Set DEMO_SEED=true if that is really what you want.');

            return;
        }

        $customers = $this->customers();
        $drivers = $this->drivers();
        $restaurants = Restaurant::query()->orderBy('id')->get()->keyBy('id');

        if ($restaurants->isEmpty()) {
            $this->command?->error('No restaurants. Run `php artisan db:seed` first — DemoDataSeeder builds on the real catalogue.');

            return;
        }

        $orders = $this->orders($customers, $drivers, $restaurants);
        $this->ratings($orders, $customers, $drivers);
        $this->complaints($orders, $customers);
        $this->captainApplications();
        $this->deliveryIssue($orders);
        $this->settlement($orders, $restaurants);
        $this->supportAdmin();

        $this->command?->info('Demo data: '.count($customers).' customers · '.count($drivers).' captains · '.count($orders).' orders.');
        $this->command?->info('Every demo account signs in with: Demo#2026');
    }

    // ---------------------------------------------------------------- people

    /** @return array<int, User> */
    private function customers(): array
    {
        $rows = [
            ['01001110001', 'منى عبد الرحمن', 'شارع البحر، برج النور، الدور 3، شقة 12', 31.4118, 31.4695, 2],
            ['01001110002', 'أحمد سيد', 'شارع الجيش، عمارة 14، الدور 2', 31.3891, 31.4823, 1],
            ['01001110003', 'هالة مصطفى', 'شارع النصر، فيلا 7، بجوار الصيدلية', 31.4062, 31.4744, 2],
            ['01001110004', 'كريم فؤاد', 'شارع المستشفى، عمارة 3، الدور 5، شقة 21', 31.3848, 31.4808, 1],
            ['01001110005', 'سارة إبراهيم', 'الكورنيش، برج الشاطئ، الدور 8', 31.4155, 31.4671, 2],
            ['01001110006', 'محمود عادل', 'شارع الترعة، منزل 9', 31.3872, 31.4851, 1],
        ];

        $out = [];

        foreach ($rows as [$phone, $name, $address, $lat, $lng, $zone]) {
            $user = User::query()->firstOrCreate(
                ['phone' => $phone],
                [
                    'name' => $name,
                    'password' => Hash::make('Demo#2026'),
                    'type' => UserType::Customer,
                    'locale' => 'ar',
                    'theme' => 'dark',
                    'is_active' => true,
                    'must_change_password' => false,
                    'phone_verified_at' => now(),
                ],
            );

            $addressRow = UserAddress::query()->firstOrCreate(
                ['user_id' => $user->id, 'label' => 'home'],
                [
                    'city_id' => self::CITY_ID,
                    'service_zone_id' => $zone,
                    'title' => 'المنزل',
                    'street' => $address,
                    'contact_phone' => $phone,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'is_default' => true,
                ],
            );

            $user->forceFill(['default_address_id' => $addressRow->id])->save();
            $out[] = $user;
        }

        return $out;
    }

    /**
     * Five captains. One is deliberately stale — its last ping is 4 minutes old, so
     * `ADM-56` must draw it as «غير نشط» and refuse to assign to it (D-55: a captain
     * is live only while the last ping is younger than 90 s).
     *
     * @return array<int, Driver>
     */
    private function drivers(): array
    {
        $rows = [
            // phone, name, plate, lat, lng, online, available, ping age (seconds), completed, rating
            ['01002220001', 'كابتن يوسف', 'ج م ص 4471', 31.4055, 31.4718, true, true, 20, 184, 4.8],
            ['01002220002', 'كابتن رامي', 'ج م ص 5210', 31.3884, 31.4836, true, true, 45, 96, 4.6],
            ['01002220003', 'كابتن طارق', 'ج م ص 6033', 31.4021, 31.4761, true, false, 30, 231, 4.9],
            ['01002220004', 'كابتن وليد', 'ج م ص 7188', 31.3908, 31.4802, true, true, 240, 58, 4.3],
            ['01002220005', 'كابتن عمرو', 'ج م ص 8902', 31.4090, 31.4703, false, false, 3600, 12, 4.1],
        ];

        $out = [];

        foreach ($rows as [$phone, $name, $plate, $lat, $lng, $online, $available, $pingAge, $completed, $rating]) {
            $user = User::query()->firstOrCreate(
                ['phone' => $phone],
                [
                    'name' => $name,
                    'password' => Hash::make('Demo#2026'),
                    'type' => UserType::Driver,
                    'locale' => 'ar',
                    'theme' => 'dark',
                    'is_active' => true,
                    'must_change_password' => false,
                    'phone_verified_at' => now(),
                ],
            );

            $pingedAt = now()->subSeconds($pingAge);

            $driver = Driver::query()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'city_id' => self::CITY_ID,
                    'national_id' => '2900'.substr($phone, -7),
                    'vehicle_type' => 'motorcycle',
                    'vehicle_plate' => $plate,
                    'earning_type' => 'per_km',
                    'earning_value' => 4.0,
                    'is_active' => true,
                ],
            );

            $driver->forceFill([
                'current_lat' => $lat,
                'current_lng' => $lng,
                'location_updated_at' => $pingedAt,
                'is_online' => $online,
                'online_since' => $online ? now()->subHours(3) : null,
                'is_available' => $available,
                'rating_avg' => $rating,
                'rating_count' => max(1, (int) round($completed * 0.7)),
                'completed_orders' => $completed,
                'cash_in_hand' => $online ? 145.0 : 0.0,
            ])->save();

            DriverLocation::query()->firstOrCreate(
                ['driver_id' => $driver->id, 'recorded_at' => $pingedAt],
                ['latitude' => $lat, 'longitude' => $lng, 'speed' => $online ? 18.4 : 0],
            );

            $driver->setRelation('user', $user);
            $out[] = $driver;
        }

        return $out;
    }

    /**
     * A second admin, scoped to Gamasa and holding only the `support` role, so
     * D-50's «role ∩ scope» and D-51's «a permission you lack is hidden, not
     * disabled» can both be seen without editing anyone's roles by hand.
     */
    private function supportAdmin(): void
    {
        $user = User::query()->firstOrCreate(
            ['phone' => '01000000077'],
            [
                'name' => 'مسؤول الدعم — جمصة',
                'email' => 'support.demo@cr7delivery.com',
                'password' => Hash::make('Demo#2026'),
                'type' => UserType::Admin,
                'locale' => 'ar',
                'theme' => 'dark',
                'is_active' => true,
                'must_change_password' => false,
                'phone_verified_at' => now(),
            ],
        );

        $user->syncRoles(['support']);
        $user->adminProfile()->firstOrCreate([], ['accepted_at' => now(), 'must_change_password' => false]);
        $user->adminScopes()->firstOrCreate(
            ['scope_type' => AdminScopeType::City, 'city_id' => self::CITY_ID],
        );
    }

    // ---------------------------------------------------------------- orders

    /**
     * @param  array<int, User>  $customers
     * @param  array<int, Driver>  $drivers
     * @param  \Illuminate\Support\Collection<int, Restaurant>  $restaurants
     * @return array<int, Order>
     */
    private function orders(array $customers, array $drivers, $restaurants): array
    {
        // status, restaurant, customer index, driver index|null, minutes ago, prep minutes
        $specs = [
            // --- RES-03 column 1: «جديد» — the accept/reject window is open.
            ['pending', 1, 0, null, 2, null],
            ['pending', 1, 1, null, 6, null],
            ['pending', 4, 2, null, 4, null],

            // --- column 2: accepted, waiting on the customer (D-40). No «بدء التحضير».
            ['awaiting_customer_confirmation', 1, 3, null, 9, 20],
            ['awaiting_customer_confirmation', 2, 4, null, 12, 25],

            // --- column 3: confirmed — this is the one that CAN start preparing.
            ['confirmed', 1, 5, null, 14, 20],
            ['confirmed', 3, 0, null, 11, 25],

            // --- column 4: preparing — one comfortable, one inside the 25 % warning
            // band, one already overdue, so `RES-03`'s countdown shows all three tones.
            // Comfortable, inside the 25 % warning band, and overdue — the countdown
            // has three tones and an empty board proves none of them.
            ['preparing', 1, 1, null, 8, 25],
            ['preparing', 1, 2, null, 26, 25],
            ['preparing', 1, 4, null, 60, 25],
            ['preparing', 5, 3, null, 55, 30],

            // --- column 5: ready / searching for a captain.
            ['ready', 1, 4, null, 30, 20],
            ['searching_driver', 2, 5, null, 33, 25],
            ['waiting_for_driver', 6, 0, null, 41, 20],

            // --- column 6: with a captain.
            ['driver_assigned', 1, 1, 0, 36, 20],
            ['arrived_restaurant', 3, 2, 1, 44, 25],
            ['picked_up', 1, 3, 2, 52, 20],
            ['on_the_way', 4, 4, 0, 58, 25],
            ['arrived_customer', 2, 5, 1, 63, 25],

            // --- history: delivered and cancelled, spread over the last ten days.
            ['delivered', 1, 0, 0, 95, 20],
            ['delivered', 1, 1, 1, 240, 20],
            ['delivered', 1, 2, 2, 1_500, 25],
            ['delivered', 2, 3, 0, 2_900, 25],
            ['delivered', 3, 4, 1, 4_300, 25],
            ['delivered', 4, 5, 2, 5_800, 30],
            ['delivered', 1, 3, 3, 7_200, 20],
            ['delivered', 5, 0, 0, 8_800, 30],
            ['delivered', 6, 1, 1, 10_100, 20],
            ['delivered', 1, 2, 2, 11_500, 25],
            ['cancelled', 1, 4, null, 320, 20],
            ['cancelled', 7, 5, null, 3_100, 20],
        ];

        $out = [];

        foreach ($specs as $index => [$status, $restaurantId, $customerIndex, $driverIndex, $minutesAgo, $prepMinutes]) {
            $restaurant = $restaurants->get($restaurantId);

            if ($restaurant === null) {
                continue;
            }

            $out[] = $this->order(
                key: 'DEMO-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                status: OrderStatus::from($status),
                restaurant: $restaurant,
                customer: $customers[$customerIndex % count($customers)],
                driver: $driverIndex === null ? null : $drivers[$driverIndex % count($drivers)],
                placedAt: now()->subMinutes($minutesAgo),
                prepMinutes: $prepMinutes,
            );
        }

        $out[] = $this->directOrder($restaurants->get(1));

        return array_values(array_filter($out));
    }

    private function order(
        string $key,
        OrderStatus $status,
        Restaurant $restaurant,
        User $customer,
        ?Driver $driver,
        Carbon $placedAt,
        ?int $prepMinutes,
    ): ?Order {
        // The sub-order carries `<key>-1`; looking for the bare key would never match
        // and the seeder would build a second copy on every run.
        $existing = Order::query()->where('idempotency_key', $key.'-1')->first();

        if ($existing !== null) {
            return $existing;
        }

        $products = Product::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_available', true)
            ->inRandomOrder()
            ->limit(random_int(1, 3))
            ->get();

        if ($products->isEmpty()) {
            return null;
        }

        $lines = $products->map(function (Product $product): array {
            $quantity = random_int(1, 2);
            $unitPrice = (float) ($product->discount_price ?? $product->price);

            return [
                'product' => $product,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => round($unitPrice * $quantity, 2),
            ];
        });

        $subtotal = round((float) $lines->sum('line_total'), 2);
        $address = $customer->addresses()->first();
        $commissionRate = (float) $restaurant->commission_rate;
        $deliveryFee = $this->zoneFee($address?->service_zone_id);
        $driverEarning = round($deliveryFee * 0.84, 2);
        $total = round($subtotal + $deliveryFee + self::PLATFORM_FEE, 2);

        $group = OrderGroup::query()->create([
            'group_number' => 'CR7-'.$placedAt->format('ymd').'-'.$key,
            'idempotency_key' => $key,
            'origin' => 'app',
            'user_id' => $customer->id,
            'address_id' => $address?->id,
            'city_id' => self::CITY_ID,
            'service_zone_id' => $address?->service_zone_id,
            'payment_method' => 'cash',
            'restaurant_count' => 1,
            'dispatch_mode' => 'driver_per_restaurant',
            'delivery_address_text' => $address?->street,
            'delivery_contact_phone' => $customer->phone,
            'delivery_latitude' => $address?->latitude,
            'delivery_longitude' => $address?->longitude,
            'max_distance_km' => 4.2,
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'delivery_base_fee' => $deliveryFee,
            'extra_stop_fee_total' => 0,
            'delivery_fee' => $deliveryFee,
            'platform_fee' => self::PLATFORM_FEE,
            'total' => $total,
            'rollup_status' => $status->value,
            'placed_at' => $placedAt,
            'completed_at' => $status === OrderStatus::Delivered ? $placedAt->copy()->addMinutes(48) : null,
        ]);

        $order = Order::query()->create([
            'order_group_id' => $group->id,
            'origin' => 'app',
            'order_number' => $group->group_number.'-1',
            'group_sequence' => 1,
            'idempotency_key' => $key.'-1',
            'user_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'driver_id' => $driver?->id,
            'city_id' => self::CITY_ID,
            'service_zone_id' => $address?->service_zone_id,
            'status' => $status,
            'payment_method' => 'cash',
            'restaurant_name_snapshot' => $restaurant->name_ar,
            'customer_name_snapshot' => $customer->name,
            'delivery_address_text' => $address?->street,
            'delivery_contact_phone' => $customer->phone,
            'delivery_latitude' => $address?->latitude,
            'delivery_longitude' => $address?->longitude,
            'distance_km' => 4.2,
            'subtotal' => $subtotal,
            'discount_share' => 0,
            'delivery_fee_share' => $deliveryFee,
            'platform_fee_share' => self::PLATFORM_FEE,
            'total' => $total,
            'min_order_value_snapshot' => $restaurant->min_order_value,
            'commission_rate_snapshot' => $commissionRate,
            'commission_amount' => round($subtotal * $commissionRate / 100, 2),
            'driver_earning' => $driver === null ? 0 : $driverEarning,
            'platform_delivery_margin' => $driver === null ? 0 : round($deliveryFee - $driverEarning, 2),
            'pickup_otp' => '1234',
            // doc 05 §8 — the customer's code only exists once the food is in the bag.
            'delivery_otp' => $this->hasPassed($status, OrderStatus::PickedUp) ? '1234' : null,
            'restaurant_order_ref' => $this->hasPassed($status, OrderStatus::Preparing)
                ? $this->posNumber($restaurant->id, $placedAt->toDateString())
                : null,
            'business_date' => $placedAt->toDateString(),
            'prep_minutes' => $prepMinutes,
            'prep_extended_minutes' => 0,
            'dispatch_round' => $this->hasPassed($status, OrderStatus::SearchingDriver) ? 1 : 0,
            'customer_note' => $key === 'DEMO-001' ? 'من فضلك بدون بصل.' : null,
            'cancelled_by' => $status === OrderStatus::Cancelled ? 'customer' : null,
            'cancellation_reason_code' => $status === OrderStatus::Cancelled ? 'changed_mind' : null,
            ...$this->timestamps($status, $placedAt, $prepMinutes),
        ]);

        // Every report groups by `created_at` (ReportController), so an order placed
        // ten days ago has to say so there too, not only in `placed_at`.
        $group->forceFill(['created_at' => $placedAt, 'updated_at' => $placedAt])->save();
        $order->forceFill(['created_at' => $placedAt, 'updated_at' => $placedAt])->save();

        foreach ($lines as $line) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $line['product']->id,
                'product_name_ar_snapshot' => $line['product']->name_ar,
                'product_name_en_snapshot' => $line['product']->name_en ?? $line['product']->name_ar,
                'product_image_snapshot' => $line['product']->image,
                'unit_price_snapshot' => $line['unit_price'],
                'options_total' => 0,
                'quantity' => $line['quantity'],
                'line_total' => $line['line_total'],
            ]);
        }

        $this->history($order, $status, $placedAt);

        return $order;
    }

    /**
     * D-47…D-49 — a phone order the app never saw. Zero commission on food,
     * `subtotal = 0`, the captain collects the delivery fee in cash, pickup OTP
     * kept, no delivery code.
     */
    private function directOrder(?Restaurant $restaurant): ?Order
    {
        if ($restaurant === null) {
            return null;
        }

        $key = 'DEMO-DIRECT-001';
        $existing = Order::query()->where('idempotency_key', $key.'-1')->first();

        if ($existing !== null) {
            return $existing;
        }

        $placedAt = now()->subMinutes(17);
        $deliveryFee = $this->zoneFee(2);

        $group = OrderGroup::query()->create([
            'group_number' => 'CR7-'.$placedAt->format('ymd').'-'.$key,
            'idempotency_key' => $key,
            'origin' => 'restaurant_direct',
            'city_id' => self::CITY_ID,
            'service_zone_id' => 2,
            'payment_method' => 'cash',
            'restaurant_count' => 1,
            'dispatch_mode' => 'driver_per_restaurant',
            'delivery_address_text' => 'شارع البحر، أمام مسجد النور',
            'delivery_contact_phone' => '01001119999',
            'delivery_latitude' => 31.4101,
            'delivery_longitude' => 31.4709,
            'max_distance_km' => 3.1,
            'subtotal' => 0,
            'discount_amount' => 0,
            'delivery_base_fee' => $deliveryFee,
            'extra_stop_fee_total' => 0,
            'delivery_fee' => $deliveryFee,
            'platform_fee' => self::PLATFORM_FEE,
            'total' => round($deliveryFee + self::PLATFORM_FEE, 2),
            'rollup_status' => 'searching_driver',
            'placed_at' => $placedAt,
        ]);

        $order = Order::query()->create([
            'order_group_id' => $group->id,
            'origin' => 'restaurant_direct',
            'created_by_restaurant_user_id' => $restaurant->user_id,
            'guest_customer_name' => 'عميل هاتفي — أ. سمير',
            'guest_customer_phone' => '01001119999',
            'declared_items_text' => "2 × سندوتش فراخ\n1 × بطاطس كبير\n2 × مياه معدنية",
            'declared_order_value' => 165,
            'food_payment' => 'paid_at_restaurant',
            'order_number' => $group->group_number.'-1',
            'group_sequence' => 1,
            'idempotency_key' => $key.'-1',
            'restaurant_id' => $restaurant->id,
            'city_id' => self::CITY_ID,
            'service_zone_id' => 2,
            'status' => OrderStatus::SearchingDriver,
            'payment_method' => 'cash',
            'restaurant_name_snapshot' => $restaurant->name_ar,
            'customer_name_snapshot' => 'عميل هاتفي — أ. سمير',
            'delivery_address_text' => 'شارع البحر، أمام مسجد النور',
            'delivery_contact_phone' => '01001119999',
            'delivery_latitude' => 31.4101,
            'delivery_longitude' => 31.4709,
            'distance_km' => 3.1,
            'subtotal' => 0,
            'discount_share' => 0,
            'delivery_fee_share' => $deliveryFee,
            'platform_fee_share' => self::PLATFORM_FEE,
            'total' => round($deliveryFee + self::PLATFORM_FEE, 2),
            'min_order_value_snapshot' => 0,
            // D-48 — zero commission on food the platform never sold.
            'commission_rate_snapshot' => 0,
            'commission_amount' => 0,
            'driver_earning' => round($deliveryFee * 0.84, 2),
            'platform_delivery_margin' => round($deliveryFee * 0.16, 2),
            'pickup_otp' => '1234',
            'delivery_otp' => null,
            'business_date' => $placedAt->toDateString(),
            'dispatch_round' => 1,
            'prep_extended_minutes' => 0,
            'placed_at' => $placedAt,
            'ready_at' => $placedAt,
            'dispatch_started_at' => $placedAt->copy()->addMinutes(1),
        ]);

        $group->forceFill(['created_at' => $placedAt, 'updated_at' => $placedAt])->save();
        $order->forceFill(['created_at' => $placedAt, 'updated_at' => $placedAt])->save();

        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => 'searching_driver',
            'actor_type' => 'restaurant',
            'actor_id' => $restaurant->user_id,
        ])->forceFill(['created_at' => $placedAt])->save();

        return $order;
    }

    /** @return array<string, Carbon|null> */
    private function timestamps(OrderStatus $status, Carbon $placedAt, ?int $prepMinutes): array
    {
        $at = fn (int $minutes): ?Carbon => $placedAt->copy()->addMinutes($minutes);

        $reached = fn (OrderStatus $step): bool => $this->hasPassed($status, $step);

        $preparingAt = $reached(OrderStatus::Preparing) ? $at(6) : null;

        return [
            'accepted_at' => $reached(OrderStatus::Accepted) ? $at(2) : null,
            'confirmed_at' => $reached(OrderStatus::Confirmed) ? $at(5) : null,
            'preparing_at' => $preparingAt,
            'prep_due_at' => $preparingAt?->copy()->addMinutes($prepMinutes ?? 20),
            'ready_at' => $reached(OrderStatus::Ready) ? $at(6 + ($prepMinutes ?? 20)) : null,
            'dispatch_started_at' => $reached(OrderStatus::SearchingDriver) ? $at(7 + ($prepMinutes ?? 20)) : null,
            'driver_assigned_at' => $reached(OrderStatus::DriverAssigned) ? $at(9 + ($prepMinutes ?? 20)) : null,
            'arrived_restaurant_at' => $reached(OrderStatus::ArrivedRestaurant) ? $at(15 + ($prepMinutes ?? 20)) : null,
            'picked_up_at' => $reached(OrderStatus::PickedUp) ? $at(18 + ($prepMinutes ?? 20)) : null,
            'arrived_customer_at' => $reached(OrderStatus::ArrivedCustomer) ? $at(30 + ($prepMinutes ?? 20)) : null,
            'delivered_at' => $status === OrderStatus::Delivered ? $at(34 + ($prepMinutes ?? 20)) : null,
            'cancelled_at' => $status === OrderStatus::Cancelled ? $at(4) : null,
        ];
    }

    /**
     * Has `$status` reached `$step` on the happy path? The 19 states are a graph,
     * not a line, but the demo rows only ever walk the happy path, so a fixed order
     * is enough — and it keeps `cancelled` from claiming it was ever picked up.
     */
    private function hasPassed(OrderStatus $status, OrderStatus $step): bool
    {
        $path = [
            OrderStatus::Pending, OrderStatus::Accepted, OrderStatus::AwaitingCustomerConfirmation,
            OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready,
            OrderStatus::SearchingDriver, OrderStatus::WaitingForDriver, OrderStatus::DriverAssigned,
            OrderStatus::ArrivedRestaurant, OrderStatus::PickedUp, OrderStatus::OnTheWay,
            OrderStatus::ArrivedCustomer, OrderStatus::Delivered,
        ];

        if ($status === OrderStatus::Cancelled) {
            return false;
        }

        $here = array_search($status, $path, true);
        $there = array_search($step, $path, true);

        return $here !== false && $there !== false && $here >= $there;
    }

    private function zoneFee(?int $zoneId): float
    {
        if ($zoneId === null) {
            return 0.0;
        }

        if ($this->zoneFees === []) {
            $this->zoneFees = ServiceZone::query()->pluck('delivery_fee', 'id')
                ->map(fn ($fee) => (float) $fee)->all();
        }

        return $this->zoneFees[$zoneId] ?? 0.0;
    }

    private function posNumber(int $restaurantId, string $businessDate): string
    {
        $key = $restaurantId.'|'.$businessDate;
        $this->posCounters[$key] = ($this->posCounters[$key] ?? 100) + 1;

        return (string) $this->posCounters[$key];
    }

    private function history(Order $order, OrderStatus $status, Carbon $placedAt): void
    {
        $steps = [['pending', 'customer', 0]];

        foreach (
            [
                [OrderStatus::Accepted, 'accepted', 'restaurant', 2],
                [OrderStatus::AwaitingCustomerConfirmation, 'awaiting_customer_confirmation', 'system', 2],
                [OrderStatus::Confirmed, 'confirmed', 'customer', 5],
                [OrderStatus::Preparing, 'preparing', 'restaurant', 6],
                [OrderStatus::Ready, 'ready', 'restaurant', 26],
                [OrderStatus::SearchingDriver, 'searching_driver', 'system', 27],
                [OrderStatus::DriverAssigned, 'driver_assigned', 'system', 29],
                [OrderStatus::ArrivedRestaurant, 'arrived_restaurant', 'driver', 35],
                [OrderStatus::PickedUp, 'picked_up', 'driver', 38],
                [OrderStatus::OnTheWay, 'on_the_way', 'driver', 39],
                [OrderStatus::ArrivedCustomer, 'arrived_customer', 'driver', 50],
                [OrderStatus::Delivered, 'delivered', 'driver', 54],
            ] as [$step, $value, $actor, $offset]
        ) {
            if ($this->hasPassed($status, $step)) {
                $steps[] = [$value, $actor, $offset];
            }
        }

        if ($status === OrderStatus::Cancelled) {
            $steps[] = ['cancelled', 'customer', 4];
        }

        $previous = null;

        foreach ($steps as [$value, $actor, $offset]) {
            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'from_status' => $previous,
                'to_status' => $value,
                'actor_type' => $actor,
            ])->forceFill(['created_at' => $placedAt->copy()->addMinutes($offset)])->save();

            $previous = $value;
        }
    }

    // ------------------------------------------------------- what hangs off them

    /**
     * @param  array<int, Order>  $orders
     * @param  array<int, User>  $customers
     * @param  array<int, Driver>  $drivers
     */
    private function ratings(array $orders, array $customers, array $drivers): void
    {
        $comments = [
            5 => 'الطلب وصل ساخن وفي الوقت. شكراً.',
            4 => 'الطعم ممتاز، والتوصيل تأخر قليلاً.',
            3 => 'الكمية أقل من المتوقع.',
            2 => 'الطلب وصل بارداً.',
        ];

        $stars = [5, 5, 4, 5, 3, 4, 5, 2, 4, 5];
        $index = 0;

        foreach ($orders as $order) {
            if ($order->status !== OrderStatus::Delivered || $order->user_id === null) {
                continue;
            }

            $value = $stars[$index % count($stars)];
            $index++;

            Rating::query()->firstOrCreate(
                ['order_id' => $order->id, 'rateable_type' => 'restaurant', 'rateable_id' => $order->restaurant_id],
                [
                    'user_id' => $order->user_id,
                    'stars' => $value,
                    'tags' => $value >= 4 ? ['سريع', 'ساخن'] : ['بطيء'],
                    'comment' => $comments[$value] ?? null,
                    'is_hidden' => false,
                ],
            );

            if ($order->driver_id !== null) {
                Rating::query()->firstOrCreate(
                    ['order_id' => $order->id, 'rateable_type' => 'driver', 'rateable_id' => $order->driver_id],
                    ['user_id' => $order->user_id, 'stars' => min(5, $value + 1), 'is_hidden' => false],
                );
            }
        }

        // Keep the aggregate the dashboards read in step with the rows just written.
        foreach (array_unique(array_map(fn (Order $o) => $o->restaurant_id, $orders)) as $restaurantId) {
            $rows = Rating::query()->where('rateable_type', 'restaurant')->where('rateable_id', $restaurantId);
            $count = (clone $rows)->count();

            if ($count > 0) {
                Restaurant::query()->whereKey($restaurantId)->update([
                    'rating_avg' => round((float) (clone $rows)->avg('stars'), 2),
                    'rating_count' => $count,
                ]);
            }
        }

        unset($customers, $drivers);
    }

    /**
     * D-53 — the box is two-sided. The food-quality one is mirrored to the
     * restaurant (`visible_to_restaurant`); the payment one is not, because a
     * restaurant has no business reading a customer's payment dispute.
     *
     * @param  array<int, Order>  $orders
     * @param  array<int, User>  $customers
     */
    private function complaints(array $orders, array $customers): void
    {
        $delivered = array_values(array_filter($orders, fn (Order $o) => $o->status === OrderStatus::Delivered));

        if ($delivered === []) {
            return;
        }

        $rows = [
            ['DEMO-CMP-1', 'food_quality', 'الطلب وصل بارداً', 'الوجبة وصلت باردة وكانت الفراخ غير ناضجة بالكامل.', 'open', 'high', true, 0],
            ['DEMO-CMP-2', 'missing_item', 'عنصر ناقص', 'لم يصل طبق البطاطس مع الطلب رغم أنه مدفوع.', 'in_progress', 'normal', true, 1],
            ['DEMO-CMP-3', 'payment', 'خصم مرتين', 'تم خصم قيمة الطلب مرتين من المحفظة.', 'open', 'high', false, 2],
            ['DEMO-CMP-4', 'late_delivery', 'تأخير في التوصيل', 'الطلب تأخر أكثر من ساعة عن الوقت المعلن.', 'resolved', 'normal', true, 3],
        ];

        foreach ($rows as [$ticket, $category, $subject, $description, $status, $priority, $mirrored, $orderIndex]) {
            $order = $delivered[$orderIndex % count($delivered)];

            $complaint = Complaint::query()->firstOrCreate(
                ['ticket_number' => $ticket],
                [
                    'user_id' => $order->user_id,
                    'user_role' => 'customer',
                    'order_id' => $order->id,
                    'order_group_id' => $order->order_group_id,
                    'restaurant_id' => $order->restaurant_id,
                    'category' => $category,
                    'subject' => $subject,
                    'description' => $description,
                    'status' => $status,
                    'priority' => $priority,
                    'visible_to_restaurant' => $mirrored,
                    'due_at' => now()->addHours(24),
                    'first_response_at' => $status === 'open' ? null : now()->subHours(3),
                    'resolved_at' => $status === 'resolved' ? now()->subHours(2) : null,
                    'resolution_outcome' => $status === 'resolved' ? 'partial_refund' : null,
                ],
            );

            ComplaintMessage::query()->firstOrCreate(
                ['complaint_id' => $complaint->id, 'sender_role' => 'customer'],
                [
                    'sender_id' => $order->user_id,
                    'visibility' => 'all',
                    'message' => $description,
                ],
            );

            if ($status !== 'open') {
                ComplaintMessage::query()->firstOrCreate(
                    ['complaint_id' => $complaint->id, 'sender_role' => 'admin'],
                    [
                        'sender_id' => 1,
                        'visibility' => 'all',
                        'message' => 'نعتذر عن الأمر. جارٍ مراجعة الطلب مع المطعم وسنوافيك بالنتيجة خلال 24 ساعة.',
                    ],
                );

                // D-53 — an internal note is never mirrored.
                ComplaintMessage::query()->firstOrCreate(
                    ['complaint_id' => $complaint->id, 'sender_role' => 'admin', 'visibility' => 'internal'],
                    ['sender_id' => 1, 'message' => 'ملاحظة داخلية: ثالث شكوى على نفس الفرع هذا الأسبوع.'],
                );
            }
        }

        unset($customers);
    }

    private function captainApplications(): void
    {
        $rows = [
            ['DEMO-APP-1', 'إبراهيم شعبان', '01003330001', 'motorcycle', 'pending'],
            ['DEMO-APP-2', 'مصطفى لطفي', '01003330002', 'scooter', 'pending'],
            ['DEMO-APP-3', 'هشام عوض', '01003330003', 'motorcycle', 'under_review'],
        ];

        foreach ($rows as [$reference, $name, $phone, $vehicle, $status]) {
            CaptainApplication::query()->firstOrCreate(
                ['reference' => $reference],
                [
                    'full_name' => $name,
                    'phone' => $phone,
                    'city_id' => self::CITY_ID,
                    'vehicle_type' => $vehicle,
                    'status' => $status,
                ],
            );
        }
    }

    /** @param  array<int, Order>  $orders */
    private function deliveryIssue(array $orders): void
    {
        $onTheWay = null;

        foreach ($orders as $order) {
            if ($order->status === OrderStatus::OnTheWay) {
                $onTheWay = $order;
                break;
            }
        }

        if ($onTheWay === null) {
            return;
        }

        DeliveryIssue::query()->firstOrCreate(
            ['order_id' => $onTheWay->id],
            [
                'reported_by' => 'driver',
                // `reporter_id` points at `users`, not at `drivers`.
                'reporter_id' => Driver::query()->whereKey($onTheWay->driver_id)->value('user_id'),
                'category' => 'customer_unreachable',
                'description' => 'العميل لا يرد على الهاتف منذ 8 دقائق.',
                'status' => 'open',
            ],
        );
    }

    /**
     * One draft settlement for the busiest restaurant, so `RES-22` and `ADM-36`
     * have a row and `RES-23` has lines to open.
     *
     * @param  array<int, Order>  $orders
     * @param  \Illuminate\Support\Collection<int, Restaurant>  $restaurants
     */
    private function settlement(array $orders, $restaurants): void
    {
        $restaurant = $restaurants->get(1);

        if ($restaurant === null) {
            return;
        }

        $lines = array_values(array_filter(
            $orders,
            fn (Order $o) => $o->restaurant_id === $restaurant->id && $o->status === OrderStatus::Delivered,
        ));

        if ($lines === []) {
            return;
        }

        $gross = round(array_sum(array_map(fn (Order $o) => (float) $o->subtotal, $lines)), 2);
        $commission = round(array_sum(array_map(fn (Order $o) => (float) $o->commission_amount, $lines)), 2);
        $delivery = round(array_sum(array_map(fn (Order $o) => (float) $o->delivery_fee_share, $lines)), 2);
        $platform = round(array_sum(array_map(fn (Order $o) => (float) $o->platform_fee_share, $lines)), 2);

        $settlement = Settlement::query()->firstOrCreate(
            [
                'party_type' => 'restaurant',
                'party_id' => $restaurant->id,
                'period_start' => now()->startOfWeek()->toDateString(),
            ],
            [
                'period_end' => now()->endOfWeek()->toDateString(),
                'gross_sales' => $gross,
                'refunds_total' => 0,
                'commission_total' => $commission,
                'delivery_fees_total' => $delivery,
                'platform_fee_total' => $platform,
                'cash_collected' => round($gross + $delivery + $platform, 2),
                'net_amount' => round($gross - $commission, 2),
                'status' => 'draft',
                'created_by' => 1,
            ],
        );

        foreach ($lines as $order) {
            SettlementItem::query()->firstOrCreate(
                ['settlement_id' => $settlement->id, 'order_id' => $order->id],
                [
                    'type' => 'order',
                    'gross' => (float) $order->subtotal,
                    'commission' => (float) $order->commission_amount,
                    'delivery_fee' => (float) $order->delivery_fee_share,
                    'platform_fee' => (float) $order->platform_fee_share,
                    'driver_earning' => (float) $order->driver_earning,
                    'net' => round((float) $order->subtotal - (float) $order->commission_amount, 2),
                ],
            );
        }
    }
}
