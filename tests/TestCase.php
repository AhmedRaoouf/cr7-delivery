<?php

namespace Tests;

use App\Models\Cart;
use App\Models\City;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\ServiceZone;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\Settings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected City $city;

    protected ServiceZone $zone;

    /** One Gamasa, one zone, and the settings the pricer reads. */
    protected function seedLocation(float $deliveryFee = 20.0, ?float $freeDeliveryOver = null): void
    {
        $this->city = City::query()->create([
            'name_ar' => 'جمصة',
            'name_en' => 'Gamasa',
            'center_lat' => 31.3833,
            'center_lng' => 31.4833,
            'pricing_type' => 'fixed_zone',
            'default_min_order' => 50,
            'is_active' => true,
        ]);

        $this->zone = $this->city->serviceZones()->create([
            'name_ar' => 'وسط جمصة',
            'name_en' => 'Gamasa Center',
            'center_lat' => 31.3833,
            'center_lng' => 31.4833,
            'radius_km' => 3,
            'delivery_fee' => $deliveryFee,
            'free_delivery_over' => $freeDeliveryOver,
            'min_order_value' => 0,
            'avg_delivery_minutes' => 30,
            'is_active' => true,
        ]);

        Settings::set('delivery.extra_stop_fee', 10.00);
        Settings::set('platform.order_fee', 5.00);
        Settings::set('platform.order_fee_applies_to_direct', true);
    }

    protected function makeRestaurant(string $name, float $lat = 31.3840, float $lng = 31.4840): Restaurant
    {
        $owner = User::query()->create([
            'name' => $name,
            'phone' => '010'.random_int(10000000, 99999999),
            'password' => Hash::make('secret1234'),
            'type' => 'restaurant',
            'is_active' => true,
        ]);

        return Restaurant::query()->create([
            'user_id' => $owner->id,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'name_ar' => $name,
            'name_en' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'phone' => $owner->phone,
            'latitude' => $lat,
            'longitude' => $lng,
            'commission_rate' => 10,
            'min_order_value' => 0,
            'avg_prep_minutes' => 20,
            'is_open_manual' => true,
            'is_active' => true,
        ]);
    }

    protected function makeProduct(Restaurant $restaurant, string $name, float $price): Product
    {
        $category = MenuCategory::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'name_ar' => 'القائمة'],
            ['name_en' => 'Menu', 'sort_order' => 0, 'is_active' => true],
        );

        return Product::query()->create([
            'restaurant_id' => $restaurant->id,
            'menu_category_id' => $category->id,
            'name_ar' => $name,
            'name_en' => $name,
            'price' => $price,
            'is_available' => true,
        ]);
    }

    protected function makeCustomer(): User
    {
        return User::query()->create([
            'name' => 'أحمد',
            'phone' => '010'.random_int(10000000, 99999999),
            'type' => 'customer',
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);
    }

    protected function makeAddress(User $user): UserAddress
    {
        $address = UserAddress::query()->create([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'label' => 'home',
            'street' => 'شارع البحر',
            'latitude' => 31.3835,
            'longitude' => 31.4835,
            'is_default' => true,
        ]);

        $user->update(['default_address_id' => $address->id]);

        return $address;
    }

    /** @param  array<int, array{0: Restaurant, 1: Product, 2: int}>  $lines */
    protected function makeCart(User $user, array $lines): Cart
    {
        $cart = Cart::query()->create(['user_id' => $user->id]);

        foreach ($lines as [$restaurant, $product, $quantity]) {
            $cart->items()->create([
                'restaurant_id' => $restaurant->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]);
        }

        return $cart;
    }
}
