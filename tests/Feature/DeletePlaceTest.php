<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Enums\OrderStatus;
use App\Models\Banner;
use App\Models\CartItem;
use App\Models\Favorite;
use App\Models\HomePopup;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Doc 91 — «عايز اي حاجه تتمسح عادي وعايز الطلبات القديمة تفضل تعرض اسم المكان بعد المسح».
 *
 * A place is deleted from the admin dashboard or the admin console; afterwards nothing
 * on any screen may answer 500 because of it, and nothing may still sell its dishes.
 */
class DeletePlaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Restaurant $place;

    private Restaurant $other;

    private Product $dish;

    private Product $otherDish;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);

        $this->admin = User::factory()->create(['type' => 'admin']);
        $this->admin->assignRole('super_admin');
        $this->admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        $this->place = $this->makeRestaurant('مطبخ حواء');
        $this->other = $this->makeRestaurant('سندوتش عمر');
        $this->dish = $this->makeProduct($this->place, 'محشي', 80);
        $this->otherDish = $this->makeProduct($this->other, 'كبدة', 40);

        $this->customer = $this->makeCustomer();
        $this->makeAddress($this->customer);
    }

    private function asCustomer(): static
    {
        Sanctum::actingAs($this->customer);

        return $this->withHeaders(['X-App' => 'app', 'Accept-Language' => 'ar']);
    }

    private function deletePlace(): TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->withHeaders(['X-App' => 'admin', 'Accept-Language' => 'ar'])->deleteJson("/api/v1/admin/restaurants/{$this->place->id}");
    }

    /** One order from the place, placed through the API like the app does. */
    private function orderFromPlace(): Order
    {
        $this->asCustomer()->postJson('/api/v1/cart/items', ['product_id' => $this->dish->id, 'quantity' => 1])->assertCreated();
        $this->postJson('/api/v1/orders', [
            'address_id' => $this->customer->default_address_id,
            'payment_method' => 'cash',
        ], ['Idempotency-Key' => 'hawaa-'.uniqid()])->assertCreated();

        return Order::query()->where('restaurant_id', $this->place->id)->latest('id')->firstOrFail();
    }

    public function test_a_place_with_an_open_order_is_refused_and_says_why(): void
    {
        $order = $this->orderFromPlace();
        $this->assertFalse($order->status->isTerminal());

        $this->deletePlace()
            ->assertStatus(409)
            ->assertJsonPath('error', 'restaurant_has_open_orders')
            ->assertJsonPath('meta.open_orders', 1)
            ->assertJsonPath('message', "لا يمكن حذف المكان لأن لديه \u{202A}1\u{202C} طلبًا مفتوحًا. أنهِ الطلبات أو ألغِها أولًا.");

        // Nothing moved: not the place, not its dishes, not its owner.
        $this->assertNotSoftDeleted($this->place);
        $this->assertNotSoftDeleted($this->dish);
        $this->assertTrue($this->place->owner->fresh()->is_active);
    }

    public function test_old_orders_keep_the_place_name_and_my_orders_still_loads(): void
    {
        $order = $this->orderFromPlace();
        $order->forceFill(['status' => OrderStatus::Cancelled])->save();

        $this->deletePlace()->assertOk();
        $this->assertSoftDeleted($this->place);

        $this->asCustomer()->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.restaurants.0', 'مطبخ حواء');

        $this->getJson("/api/v1/orders/{$order->order_group_id}")->assertOk();
    }

    public function test_baskets_lose_the_places_lines_and_keep_everything_else(): void
    {
        $this->makeCart($this->customer, [[$this->place, $this->dish, 2], [$this->other, $this->otherDish, 1]]);

        $this->deletePlace()->assertOk();

        $this->assertSame(0, CartItem::query()->where('restaurant_id', $this->place->id)->count());

        $cart = $this->asCustomer()->getJson('/api/v1/cart')->assertOk();
        $this->assertSame([$this->other->id], collect($cart->json('data.groups'))->pluck('restaurant.id')->all());

        // The basket still works: more of what is left, and nothing of the place.
        $this->postJson('/api/v1/cart/items', ['product_id' => $this->otherDish->id, 'quantity' => 1])->assertCreated();
    }

    public function test_its_dishes_cannot_be_ordered_from_a_favourite_or_a_banner(): void
    {
        Favorite::query()->create(['user_id' => $this->customer->id, 'favoritable_type' => 'product', 'favoritable_id' => $this->dish->id]);
        Favorite::query()->create(['user_id' => $this->customer->id, 'favoritable_type' => 'restaurant', 'favoritable_id' => $this->place->id]);
        Favorite::query()->create(['user_id' => $this->customer->id, 'favoritable_type' => 'product', 'favoritable_id' => $this->otherDish->id]);

        $toPlace = Banner::query()->create(['title_ar' => 'حواء', 'image_ar' => 'https://example.test/a.png', 'audience' => 'all', 'is_active' => true, 'link_type' => 'restaurant', 'link_value' => (string) $this->place->id]);
        $toDish = Banner::query()->create(['title_ar' => 'محشي', 'image_ar' => 'https://example.test/b.png', 'audience' => 'all', 'is_active' => true, 'link_type' => 'product', 'link_value' => (string) $this->dish->id]);
        $elsewhere = Banner::query()->create(['title_ar' => 'كبدة', 'image_ar' => 'https://example.test/c.png', 'audience' => 'all', 'is_active' => true, 'link_type' => 'restaurant', 'link_value' => (string) $this->other->id]);
        $popup = HomePopup::query()->create(['title_ar' => 'عرض حواء', 'link_type' => 'product', 'product_id' => $this->dish->id, 'is_active' => true]);

        $this->deletePlace()->assertOk();

        $this->assertSoftDeleted($this->dish);
        $this->assertSame(0, MenuCategory::query()->where('restaurant_id', $this->place->id)->count());
        $this->assertSame([$this->otherDish->id], Favorite::query()->pluck('favoritable_id')->all());

        $this->assertFalse($toPlace->fresh()->is_active);
        $this->assertFalse($toDish->fresh()->is_active);
        $this->assertFalse($popup->fresh()->is_active);
        $this->assertTrue($elsewhere->fresh()->is_active);

        $this->asCustomer()->getJson('/api/v1/banners')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/favorites')->assertOk();
        $this->getJson("/api/v1/products/{$this->dish->id}")->assertNotFound();
        $this->getJson("/api/v1/restaurants/{$this->place->id}")->assertNotFound();
        $this->postJson('/api/v1/cart/items', ['product_id' => $this->dish->id, 'quantity' => 1])->assertNotFound();
    }

    /** A basket line left behind by anything else is flagged, never a 500. */
    public function test_a_leftover_line_of_a_deleted_place_reads_unavailable(): void
    {
        $this->makeCart($this->customer, [[$this->place, $this->dish, 1]]);
        Product::query()->whereKey($this->dish->id)->update(['deleted_at' => now()]);
        Restaurant::query()->whereKey($this->place->id)->update(['deleted_at' => now()]);

        $codes = collect($this->asCustomer()->getJson('/api/v1/cart')->assertOk()->json('data.groups.0.issues'))->pluck('code');

        $this->assertContains('restaurant_closed', $codes);
        $this->assertContains('product_unavailable', $codes);
    }

    public function test_the_owner_is_switched_off_and_signed_out_and_the_pin_is_cleared(): void
    {
        $this->place->forceFill(['pin_position' => 7])->save();
        $owner = $this->place->owner;
        $owner->createToken('board');

        $this->deletePlace()->assertOk()->assertJsonPath('message', 'تم حذف المطعم بنجاح');

        $this->assertFalse($owner->fresh()->is_active);
        $this->assertSame(0, $owner->tokens()->count());
        $this->assertNull(Restaurant::withTrashed()->find($this->place->id)->pin_position);
    }

    /** An owner who still runs another place keeps their account. */
    public function test_an_owner_of_two_places_keeps_the_account(): void
    {
        $this->other->forceFill(['user_id' => $this->place->user_id])->save();

        $this->deletePlace()->assertOk();

        $this->assertTrue($this->place->owner->fresh()->is_active);
    }

    public function test_a_promo_code_for_only_this_place_is_switched_off_not_opened_to_all(): void
    {
        $only = PromoCode::query()->create([
            'code' => 'HAWAA', 'title_ar' => 'خصم', 'title_en' => 'Discount', 'type' => 'percent', 'value' => 10,
            'is_active' => true, 'restrict_restaurant_ids' => [$this->place->id],
        ]);
        $shared = PromoCode::query()->create([
            'code' => 'BOTH', 'title_ar' => 'خصم', 'title_en' => 'Discount', 'type' => 'percent', 'value' => 10,
            'is_active' => true, 'restrict_restaurant_ids' => [$this->place->id, $this->other->id],
        ]);

        $this->deletePlace()->assertOk();

        $this->assertFalse($only->fresh()->is_active);
        $this->assertSame([$this->place->id], $only->fresh()->restrict_restaurant_ids);
        $this->assertTrue($shared->fresh()->is_active);
        $this->assertSame([$this->other->id], $shared->fresh()->restrict_restaurant_ids);
    }

    public function test_a_popup_cannot_be_pointed_at_a_deleted_place(): void
    {
        $this->deletePlace()->assertOk();

        $this->postJson('/api/v1/admin/home-popups', [
            'title_ar' => 'حواء', 'link_type' => 'restaurant', 'restaurant_id' => $this->place->id,
        ])->assertStatus(422)->assertJsonValidationErrors('restaurant_id', 'errors');
    }
}
