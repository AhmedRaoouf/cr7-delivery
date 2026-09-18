<?php

namespace Tests\Feature\Security;

use App\Actions\Customer\CreateOrderAction;
use App\Enums\OrderStatus;
use App\Models\Complaint;
use App\Models\Driver;
use App\Models\ErrandRequest;
use App\Models\MenuCategory;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\Restaurant;
use App\Models\Settlement;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 67 — the security audit's IDOR sweep, doc 12 §5: «Violations return 404, not
 * 403, so resource existence is not leaked.»
 *
 * Every route that takes an id was read for the check that stops one account reaching
 * another's row. They were all present; this file is what keeps them present. Each
 * assertion is one request a second account of the same type makes against the first
 * account's row, and every one of them must be a 404 — never a 200, and never a 403
 * that tells the caller the row exists.
 */
class CrossTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
        Storage::fake('public');
        Storage::fake('local');
    }

    // ------------------------------------------------------------------ customers

    public function test_a_customer_cannot_reach_another_customers_rows(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        [$alice, $order] = $this->customerWithOrder($restaurant);
        $group = $order->order_group_id;
        $address = $alice->addresses()->firstOrFail();
        $item = $alice->cart()->firstOrCreate([])->items()->create([
            'restaurant_id' => $restaurant->id,
            'product_id' => $this->makeProduct($restaurant, 'فول', 20)->id,
            'quantity' => 1,
        ]);
        $complaint = Complaint::query()->create([
            'ticket_number' => 'CMP-260918-AAAAA',
            'user_id' => $alice->id,
            'user_role' => 'customer',
            'order_id' => $order->id,
            'category' => 'food_quality',
            'description' => 'الطعام بارد',
            'status' => 'open',
            'priority' => 'normal',
        ]);
        $notification = Notification::query()->create([
            'user_id' => $alice->id,
            'type' => 'order.accepted',
            'title_key' => 'order.accepted',
        ]);
        $errand = $this->errandFor($alice);

        $bob = $this->makeCustomer();
        $this->makeAddress($bob);
        $this->actAs($bob, 'app');

        $this->getJson("/api/v1/orders/{$group}")->assertNotFound();
        $this->getJson("/api/v1/orders/{$group}/tracking")->assertNotFound();
        $this->getJson("/api/v1/orders/{$group}/confirmation")->assertNotFound();
        $this->getJson("/api/v1/orders/{$group}/payment")->assertNotFound();
        $this->postJson("/api/v1/orders/{$group}/cancel", ['reason_code' => 'changed_mind'])->assertNotFound();
        $this->postJson("/api/v1/orders/{$group}/confirm")->assertNotFound();
        $this->postJson("/api/v1/orders/{$group}/reorder")->assertNotFound();
        $this->postJson("/api/v1/orders/{$group}/delivery-code/resend")->assertNotFound();
        $this->postJson("/api/v1/orders/{$group}/payment/method")->assertNotFound();
        $this->postJson("/api/v1/orders/{$group}/ratings", ['ratings' => []])->assertNotFound();

        $this->patchJson("/api/v1/addresses/{$address->id}", ['street' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/addresses/{$address->id}")->assertNotFound();
        $this->postJson("/api/v1/addresses/{$address->id}/default")->assertNotFound();

        $this->patchJson("/api/v1/cart/items/{$item->id}", ['quantity' => 5])->assertNotFound();
        $this->deleteJson("/api/v1/cart/items/{$item->id}")->assertNotFound();

        $this->getJson("/api/v1/complaints/{$complaint->id}")->assertNotFound();
        $this->postJson("/api/v1/complaints/{$complaint->id}/messages", ['message' => 'x'])->assertNotFound();

        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertNotFound();

        $this->getJson("/api/v1/customer/errands/{$errand->id}")->assertNotFound();
        $this->postJson("/api/v1/customer/errands/{$errand->id}/cancel")->assertNotFound();

        // Nothing moved.
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertNotNull($address->fresh());
        $this->assertSame(1, (int) $item->fresh()->quantity);
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_a_complaint_cannot_be_filed_against_somebody_elses_order(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        [, $order] = $this->customerWithOrder($restaurant);

        $bob = $this->makeCustomer();
        $this->actAs($bob, 'app');

        $this->postJson('/api/v1/complaints', [
            'category' => 'food_quality',
            'order_id' => $order->id,
            'description' => 'طلب ليس لي',
        ])->assertCreated();

        // Filed, but not linked: the restaurant never sees a mirror of it, and the
        // ticket carries none of Alice's order.
        $complaint = Complaint::query()->where('user_id', $bob->id)->firstOrFail();
        $this->assertNull($complaint->order_id);
        $this->assertNull($complaint->restaurant_id);
        $this->assertFalse((bool) $complaint->visible_to_restaurant);
    }

    // ------------------------------------------------------------------ captains

    public function test_a_captain_cannot_act_on_an_order_assigned_to_another(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        [, $order] = $this->customerWithOrder($restaurant);
        $rami = $this->makeDriver('رامي');
        $order->update(['status' => OrderStatus::DriverAssigned, 'driver_id' => $rami->id]);

        $other = $this->makeDriver('سامي');
        $this->actAs($other->user, 'app');

        $this->getJson("/api/v1/driver/orders/{$order->id}")->assertNotFound();
        $this->getJson("/api/v1/driver/earnings/{$order->id}")->assertNotFound();
        $this->postJson("/api/v1/driver/orders/{$order->id}/arrived-restaurant")->assertNotFound();
        $this->postJson("/api/v1/driver/orders/{$order->id}/waiting")->assertNotFound();
        $this->postJson("/api/v1/driver/orders/{$order->id}/pickup", ['otp' => '0000'])->assertNotFound();
        $this->postJson("/api/v1/driver/orders/{$order->id}/on-the-way")->assertNotFound();
        $this->postJson("/api/v1/driver/orders/{$order->id}/arrived-customer")->assertNotFound();
        $this->postJson("/api/v1/driver/orders/{$order->id}/deliver", ['otp' => '0000'])->assertNotFound();
        $this->postJson("/api/v1/driver/orders/{$order->id}/cancel", ['reason_code' => 'vehicle_issue'])->assertNotFound();
        $this->postJson("/api/v1/driver/orders/{$order->id}/issue", ['category' => 'other'])->assertNotFound();

        // An offer nobody made to this captain cannot be taken or turned down.
        $order->update(['status' => OrderStatus::SearchingDriver, 'driver_id' => null]);
        $this->postJson("/api/v1/driver/orders/{$order->id}/accept")->assertStatus(403)->assertJsonPath('error', 'not_eligible');
        $this->postJson("/api/v1/driver/orders/{$order->id}/reject", ['reason_code' => 'busy'])->assertStatus(403);
        $this->getJson("/api/v1/driver/offers/{$order->id}")->assertNotFound();

        $this->assertNull($order->fresh()->driver_id);
    }

    public function test_a_captain_cannot_act_on_another_captains_errand(): void
    {
        $alice = $this->makeCustomer();
        $errand = $this->errandFor($alice);
        $rami = $this->makeDriver('رامي');
        $errand->update(['status' => 'assigned', 'driver_id' => $rami->id]);

        $other = $this->makeDriver('سامي');
        $this->actAs($other->user, 'app');

        $this->getJson("/api/v1/driver/errands/{$errand->id}")->assertNotFound();
        $this->postJson("/api/v1/driver/errands/{$errand->id}/picked-up")->assertNotFound();
        $this->postJson("/api/v1/driver/errands/{$errand->id}/deliver")->assertNotFound();
        $this->postJson("/api/v1/driver/errands/{$errand->id}/cancel", ['reason' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/driver/errands/{$errand->id}/purchases", ['purchases_amount' => 10])->assertNotFound();

        $this->assertSame($rami->id, $errand->fresh()->driver_id);
    }

    // ------------------------------------------------------------------ places

    public function test_a_place_cannot_reach_another_places_rows(): void
    {
        $mine = $this->makeRestaurant('ابن البلد');
        [, $order] = $this->customerWithOrder($mine);
        $product = Product::query()->where('restaurant_id', $mine->id)->firstOrFail();
        $category = MenuCategory::query()->where('restaurant_id', $mine->id)->firstOrFail();
        $group = ProductOptionGroup::query()->create([
            'product_id' => $product->id,
            'name_ar' => 'الحجم',
            'name_en' => 'Size',
            'min_select' => 0,
            'max_select' => 1,
        ]);
        $option = $group->options()->create(['name_ar' => 'كبير', 'name_en' => 'Large', 'price_delta' => 5]);
        $settlement = Settlement::query()->create([
            'party_type' => 'restaurant',
            'party_id' => $mine->id,
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'gross_sales' => 100,
            'net_amount' => 90,
            'status' => 'pending',
        ]);
        $complaint = Complaint::query()->create([
            'ticket_number' => 'CMP-260918-BBBBB',
            'user_id' => $order->user_id,
            'user_role' => 'customer',
            'order_id' => $order->id,
            'restaurant_id' => $mine->id,
            'visible_to_restaurant' => true,
            'category' => 'food_quality',
            'description' => 'الطعام بارد',
            'status' => 'open',
            'priority' => 'normal',
        ]);

        $rival = $this->makeRestaurant('كنج بروست');
        $this->actAs($rival->owner, 'restaurant');

        $this->getJson("/api/v1/restaurant/orders/{$order->id}")->assertNotFound();
        $this->postJson("/api/v1/restaurant/orders/{$order->id}/accept", ['prep_minutes' => 20])->assertNotFound();
        $this->postJson("/api/v1/restaurant/orders/{$order->id}/reject", ['reason_code' => 'busy'])->assertNotFound();
        $this->postJson("/api/v1/restaurant/orders/{$order->id}/start-preparing")->assertNotFound();
        $this->postJson("/api/v1/restaurant/orders/{$order->id}/ready")->assertNotFound();
        $this->patchJson("/api/v1/restaurant/orders/{$order->id}/order-ref", ['restaurant_order_ref' => '9'])->assertNotFound();
        $this->patchJson("/api/v1/restaurant/orders/{$order->id}/prep-time", ['extra_minutes' => 5])->assertNotFound();
        $this->getJson("/api/v1/restaurant/orders/{$order->id}/pickup-otp")->assertNotFound();
        $this->getJson("/api/v1/restaurant/direct-orders/{$order->id}")->assertNotFound();

        $this->getJson("/api/v1/restaurant/products/{$product->id}")->assertNotFound();
        $this->patchJson("/api/v1/restaurant/products/{$product->id}", ['price' => 1])->assertNotFound();
        $this->deleteJson("/api/v1/restaurant/products/{$product->id}")->assertNotFound();
        $this->postJson("/api/v1/restaurant/products/{$product->id}/availability", ['is_available' => false])->assertNotFound();
        $this->getJson("/api/v1/restaurant/products/{$product->id}/option-groups")->assertNotFound();
        $this->patchJson("/api/v1/restaurant/products/{$product->id}/option-groups/{$group->id}", ['name_ar' => 'x'])->assertNotFound();
        $this->getJson("/api/v1/restaurant/option-groups/{$group->id}/options")->assertNotFound();
        $this->patchJson("/api/v1/restaurant/option-groups/{$group->id}/options/{$option->id}", ['price_delta' => 0])->assertNotFound();
        $this->patchJson("/api/v1/restaurant/categories/{$category->id}", ['name_ar' => 'x', 'name_en' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/restaurant/categories/{$category->id}")->assertNotFound();

        $this->getJson("/api/v1/restaurant/settlements/{$settlement->id}")->assertNotFound();
        $this->getJson("/api/v1/restaurant/complaints/{$complaint->id}")->assertNotFound();
        $this->postJson("/api/v1/restaurant/complaints/{$complaint->id}/messages", ['message' => 'x'])->assertNotFound();

        // «Bulk» cannot reach across either: the ids are filtered to the caller's menu.
        $this->postJson('/api/v1/restaurant/products/bulk-availability', [
            'ids' => [$product->id],
            'is_available' => false,
        ])->assertOk();

        $this->assertTrue((bool) $product->fresh()->is_available);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_a_place_cannot_file_its_dish_under_another_places_category(): void
    {
        $mine = $this->makeRestaurant('ابن البلد');
        $theirs = $this->makeRestaurant('كنج بروست');
        $foreign = MenuCategory::query()->create([
            'restaurant_id' => $theirs->id,
            'name_ar' => 'بروستد',
            'name_en' => 'Broasted',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $own = $this->makeProduct($mine, 'فول', 20);

        $this->actAs($mine->owner, 'restaurant');

        $this->postJson('/api/v1/restaurant/products', [
            'menu_category_id' => $foreign->id,
            'name_ar' => 'دخيل',
            'name_en' => 'Intruder',
            'price' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('menu_category_id', 'errors');

        $this->patchJson("/api/v1/restaurant/products/{$own->id}", [
            'menu_category_id' => $foreign->id,
        ])->assertStatus(422);

        $this->assertSame(0, Product::query()->where('menu_category_id', $foreign->id)->count());
    }

    // ------------------------------------------------------------- account types

    public function test_a_token_of_one_type_cannot_call_another_types_routes(): void
    {
        $customer = $this->makeCustomer();
        $driver = $this->makeDriver('رامي');
        $place = $this->makeRestaurant('ابن البلد');
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');

        $this->actAs($customer, 'app');
        $this->getJson('/api/v1/driver/me')->assertStatus(403)->assertJsonPath('error', 'wrong_client');
        $this->getJson('/api/v1/restaurant/me')->assertStatus(403)->assertJsonPath('error', 'wrong_client');
        $this->getJson('/api/v1/admin/orders')->assertStatus(403)->assertJsonPath('error', 'wrong_client');

        $this->actAs($driver->user, 'app');
        $this->getJson('/api/v1/cart')->assertStatus(403)->assertJsonPath('error', 'wrong_client');
        $this->getJson('/api/v1/restaurant/orders')->assertStatus(403);
        $this->getJson('/api/v1/admin/users')->assertStatus(403);

        // The web origins admit exactly one type each (doc 12 §1.1).
        $this->actAs($place->owner, 'admin');
        $this->getJson('/api/v1/admin/orders')->assertStatus(403)->assertJsonPath('error', 'wrong_client');
        $this->actAs($admin, 'restaurant');
        $this->getJson('/api/v1/restaurant/orders')->assertStatus(403)->assertJsonPath('error', 'wrong_client');
        $this->actAs($customer, 'restaurant');
        $this->getJson('/api/v1/auth/me')->assertStatus(403)->assertJsonPath('error', 'wrong_client');

        // No `X-App` at all is no client at all.
        Sanctum::actingAs($customer);
        $this->withHeaders(['X-App' => ''])->getJson('/api/v1/auth/me')->assertStatus(403);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: User, 1: Order} */
    private function customerWithOrder(Restaurant $restaurant): array
    {
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer);
        $this->makeCart($customer, [[$restaurant, $this->makeProduct($restaurant, 'سندوتش', 100.0), 1]]);

        $group = app(CreateOrderAction::class)->execute(
            $customer,
            ['address_id' => $address->id, 'payment_method' => 'cash'],
            'idem-'.uniqid('', true),
        );

        return [$customer, Order::query()->where('order_group_id', $group->id)->firstOrFail()];
    }

    private function errandFor(User $customer): ErrandRequest
    {
        $this->actAs($customer, 'app');

        $this->postJson('/api/v1/customer/errands', [
            'pickup_lat' => 31.3835,
            'pickup_lng' => 31.4835,
            'pickup_address_text' => 'صيدلية النور',
            'dropoff_lat' => 31.3845,
            'dropoff_lng' => 31.4845,
            'dropoff_address_text' => 'عمارات مبارك',
            'dropoff_contact_phone' => '01099887766',
            'details' => 'علبة باراسيتامول',
            'offered_fee' => 45,
        ])->assertCreated();

        return ErrandRequest::query()->where('user_id', $customer->id)->latest('id')->firstOrFail();
    }

    private function makeDriver(string $name): Driver
    {
        $user = User::factory()->create(['type' => 'driver', 'name' => $name]);

        return Driver::query()->create([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'earning_type' => 'fixed',
            'earning_value' => 15,
            'is_active' => true,
            'is_online' => true,
            'is_available' => true,
            'current_lat' => 31.3841,
            'current_lng' => 31.4841,
            'location_updated_at' => now(),
        ]);
    }

    private function actAs(User $user, string $app): void
    {
        Sanctum::actingAs($user);
        $this->withHeader('X-App', $app);
    }
}
