<?php

namespace Tests\Concerns;

use App\Enums\AdminScopeType;
use App\Models\City;
use App\Models\Complaint;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\ServiceZone;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Batch 68 — the rows the list tests filter, built directly and pinned.
 *
 * Every phone, number and name a search could hit is set by the test, never random: the
 * export test learnt that a random `010…` phone contains «104» one run in ten, and a
 * filter test that fails one run in three reads as a broken filter.
 */
trait BuildsListFixtures
{
    protected City $otherCity;

    protected ServiceZone $otherZone;

    protected function setUpListWorld(): void
    {
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);

        $this->otherCity = City::query()->create([
            'name_ar' => 'المنصورة الجديدة',
            'name_en' => 'New Mansoura',
            'center_lat' => 31.45,
            'center_lng' => 31.46,
            'pricing_type' => 'fixed_zone',
            'default_min_order' => 50,
            'is_active' => true,
        ]);

        $this->otherZone = $this->otherCity->serviceZones()->create([
            'name_ar' => 'الحي الأول',
            'name_en' => 'District 1',
            'center_lat' => 31.45,
            'center_lng' => 31.46,
            'radius_km' => 3,
            'delivery_fee' => 20,
            'min_order_value' => 0,
            'avg_delivery_minutes' => 30,
            'is_active' => true,
        ]);
    }

    protected function globalAdmin(string $name = 'مدير المنصة', string $phone = '01000000099'): User
    {
        $admin = User::query()->create([
            'name' => $name,
            'phone' => $phone,
            'type' => 'admin',
            'is_active' => true,
        ]);

        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $admin;
    }

    /** Every permission there is, and the rows of one city only. */
    protected function cityAdmin(City $city, string $phone = '01000000088'): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'city_boss', 'guard_name' => 'web'],
            ['label_ar' => 'مدير مدينة', 'label_en' => 'City boss'],
        );
        $role->syncPermissions(Permission::query()->pluck('name')->all());

        $admin = User::query()->create([
            'name' => 'مدير مدينة',
            'phone' => $phone,
            'type' => 'admin',
            'is_active' => true,
        ]);
        $admin->assignRole('city_boss');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::City, 'city_id' => $city->id]);

        return $admin;
    }

    protected function actingAsAdmin(User $admin): static
    {
        Sanctum::actingAs($admin);

        return $this->withHeader('X-App', 'admin');
    }

    protected function actingAsPlace(Restaurant $restaurant): static
    {
        Sanctum::actingAs($restaurant->owner);

        return $this->withHeader('X-App', 'restaurant');
    }

    protected function actingAsCaptainUser(Driver $driver): static
    {
        Sanctum::actingAs($driver->user);

        return $this->withHeader('X-App', 'app');
    }

    protected function actingAsCustomer(User $customer): static
    {
        Sanctum::actingAs($customer);

        return $this->withHeader('X-App', 'app');
    }

    protected function customerNamed(string $name, string $phone, ?City $city = null): User
    {
        $customer = User::query()->create([
            'name' => $name,
            'phone' => $phone,
            'type' => 'customer',
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);

        if ($city !== null) {
            $address = $customer->addresses()->create([
                'city_id' => $city->id,
                'service_zone_id' => $city->is($this->city) ? $this->zone->id : $this->otherZone->id,
                'label' => 'home',
                'street' => 'شارع البحر',
                'latitude' => 31.3835,
                'longitude' => 31.4835,
                'is_default' => true,
            ]);
            $customer->update(['default_address_id' => $address->id]);
        }

        return $customer;
    }

    protected function captainNamed(string $name, string $phone, ?ServiceZone $zone = null, array $attributes = []): Driver
    {
        $zone ??= $this->zone;

        $user = User::query()->create([
            'name' => $name,
            'phone' => $phone,
            'type' => 'driver',
            'is_active' => true,
        ]);

        $driver = Driver::query()->create($attributes + [
            'user_id' => $user->id,
            'city_id' => $zone->city_id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_active' => true,
            'is_available' => true,
        ]);
        $driver->assignedZones()->attach($zone->id);

        return $driver;
    }

    /** One basket, one sub-order, and its payment row — every column pinned. */
    protected function orderFor(Restaurant $restaurant, array $attributes = [], ?User $customer = null): Order
    {
        $customer ??= $this->customerNamed('عميل '.Str::random(4), '0122'.random_int(1000000, 9999999));
        $number = $attributes['order_number'] ?? 'CR7-'.random_int(100000, 999999).'-1';

        $group = OrderGroup::query()->create([
            'group_number' => Str::beforeLast($number, '-'),
            'idempotency_key' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'city_id' => $attributes['city_id'] ?? $restaurant->city_id,
            'service_zone_id' => $attributes['service_zone_id'] ?? $restaurant->service_zone_id,
            'payment_method' => $attributes['payment_method'] ?? 'cash',
            'rollup_status' => $attributes['status'] ?? 'delivered',
            'subtotal' => 100,
            'delivery_fee' => 20,
            'total' => $attributes['total'] ?? 120,
        ]);

        Payment::query()->create([
            'order_group_id' => $group->id,
            'user_id' => $customer->id,
            'method' => $attributes['payment_method'] ?? 'cash',
            'provider' => 'manual',
            'status' => 'pending',
            'amount' => $attributes['total'] ?? 120,
        ]);

        $order = Order::query()->create($attributes + [
            'order_group_id' => $group->id,
            'order_number' => $number,
            'idempotency_key' => (string) Str::uuid(),
            'group_sequence' => 1,
            'user_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'restaurant_name_snapshot' => $restaurant->name_ar,
            'customer_name_snapshot' => $customer->name,
            'city_id' => $restaurant->city_id,
            'service_zone_id' => $restaurant->service_zone_id,
            'status' => 'delivered',
            'payment_method' => 'cash',
            'delivery_address_text' => 'شارع البحر',
            'delivery_latitude' => 31.3835,
            'delivery_longitude' => 31.4835,
            'subtotal' => 100,
            'commission_amount' => 10,
            'delivery_fee_share' => 20,
            'total' => 120,
        ]);

        // Whatever `$fillable` leaves out (`created_at`, the timestamps) is still what the
        // test asked for.
        if ($attributes !== []) {
            $order->forceFill($attributes)->saveQuietly();
        }

        return $order;
    }

    protected function complaintAbout(Order $order, array $attributes = []): Complaint
    {
        return Complaint::query()->create($attributes + [
            'ticket_number' => 'CMP-'.Str::upper(Str::random(6)),
            'user_id' => $order->user_id,
            'user_role' => 'customer',
            'order_id' => $order->id,
            'order_group_id' => $order->order_group_id,
            'restaurant_id' => $order->restaurant_id,
            'category' => 'food_quality',
            'subject' => 'الطعام بارد',
            'description' => 'الطعام وصل باردًا',
            'status' => 'open',
            'priority' => 'normal',
            'visible_to_restaurant' => true,
            'due_at' => now()->addDay(),
        ]);
    }

    /** Moves a restaurant (and nothing else) into the second city. */
    protected function moveToOtherCity(Restaurant $restaurant): Restaurant
    {
        $restaurant->update(['city_id' => $this->otherCity->id, 'service_zone_id' => $this->otherZone->id]);

        return $restaurant->refresh();
    }

    /**
     * The ids a list answers, in order.
     *
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    protected function idsOf(string $path, array $query = [], string $key = 'id'): array
    {
        return array_map('intval', array_column($this->list($path, $query)->json('data'), $key));
    }

    /** @param  array<string, mixed>  $query */
    protected function list(string $path, array $query = []): TestResponse
    {
        return $this->getJson($this->listUrl($path, $query))->assertOk();
    }

    /**
     * Built with `http_build_query`, never by hand: a raw UTF-8 query string reaches the
     * controller as mojibake in the test client (ListFiltersTest).
     *
     * @param  array<string, mixed>  $query
     */
    protected function listUrl(string $path, array $query): string
    {
        return $query === [] ? $path : $path.'?'.http_build_query($query);
    }
}
