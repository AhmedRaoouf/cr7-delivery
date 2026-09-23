<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * doc 77 — full, safe control of a menu on both staff surfaces: a section is never
 * deleted out from under its dishes, many dishes go at once, and sections and the
 * dishes inside one can be put in order.
 */
class MenuControlTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    private Restaurant $grill;

    private MenuCategory $mains;

    private MenuCategory $drinks;

    /** @var list<Product> */
    private array $dishes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();

        $this->grill = $this->makeRestaurant('مشويات النيل');
        $this->mains = $this->grill->menuCategories()->create(['name_ar' => 'المشويات', 'name_en' => 'Grill', 'sort_order' => 0, 'is_active' => true]);
        $this->drinks = $this->grill->menuCategories()->create(['name_ar' => 'المشروبات', 'name_en' => 'Drinks', 'sort_order' => 1, 'is_active' => true]);

        $this->dishes = collect(['كفتة', 'طرب', 'ريش'])->map(fn (string $name, int $i) => Product::query()->create([
            'restaurant_id' => $this->grill->id,
            'menu_category_id' => $this->mains->id,
            'name_ar' => $name,
            'name_en' => $name,
            'price' => 100 + $i,
            'sort_order' => $i,
            'is_available' => true,
        ]))->all();
    }

    public function test_the_category_list_says_how_many_dishes_each_section_holds(): void
    {
        $rows = collect($this->actingAsPlace($this->grill)->getJson('/api/v1/restaurant/categories')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(3, $rows[$this->mains->id]['products_count']);
        $this->assertSame(0, $rows[$this->drinks->id]['products_count']);

        $admin = $this->cityAdmin($this->city);
        $rows = collect($this->actingAsAdmin($admin)->getJson("/api/v1/admin/restaurants/{$this->grill->id}/categories")->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(3, $rows[$this->mains->id]['products_count']);
    }

    public function test_a_section_that_still_holds_dishes_is_refused_until_the_caller_says_where_they_go(): void
    {
        $this->actingAsPlace($this->grill)
            ->deleteJson("/api/v1/restaurant/categories/{$this->mains->id}")
            ->assertStatus(409)
            ->assertJsonPath('error', 'category_not_empty')
            ->assertJsonPath('meta.products_count', 3);

        $this->assertNotSoftDeleted($this->mains);
        $this->assertSame(3, Product::query()->where('menu_category_id', $this->mains->id)->count());
    }

    public function test_moving_the_dishes_keeps_them_on_the_customers_menu(): void
    {
        $this->actingAsPlace($this->grill)
            ->deleteJson("/api/v1/restaurant/categories/{$this->mains->id}", ['move_to' => $this->drinks->id])
            ->assertOk()
            ->assertJsonPath('data.moved', 3)
            ->assertJsonPath('data.deleted_products', 0);

        $this->assertSoftDeleted($this->mains);
        $this->assertSame(3, Product::query()->where('menu_category_id', $this->drinks->id)->count());

        $this->actingAsCustomer($this->makeCustomer())
            ->getJson("/api/v1/restaurants/{$this->grill->id}/products")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_the_dishes_cannot_be_moved_into_the_section_being_deleted_or_into_another_place(): void
    {
        $other = $this->makeRestaurant('مكان آخر');
        $foreign = $other->menuCategories()->create(['name_ar' => 'أخرى', 'name_en' => 'Other', 'sort_order' => 0, 'is_active' => true]);

        foreach ([$this->mains->id, $foreign->id] as $target) {
            $this->actingAsPlace($this->grill)
                ->deleteJson("/api/v1/restaurant/categories/{$this->mains->id}", ['move_to' => $target])
                ->assertStatus(422)
                ->assertJsonValidationErrors('move_to');
        }

        $this->assertNotSoftDeleted($this->mains);
    }

    public function test_the_dishes_can_go_with_their_section(): void
    {
        $this->actingAsPlace($this->grill)
            ->deleteJson("/api/v1/restaurant/categories/{$this->mains->id}?delete_products=1")
            ->assertOk()
            ->assertJsonPath('data.deleted_products', 3);

        $this->assertSoftDeleted($this->mains);
        foreach ($this->dishes as $dish) {
            $this->assertSoftDeleted($dish);
        }
    }

    public function test_an_empty_section_is_deleted_without_a_question(): void
    {
        $this->actingAsPlace($this->grill)->deleteJson("/api/v1/restaurant/categories/{$this->drinks->id}")->assertOk();
        $this->assertSoftDeleted($this->drinks);
    }

    public function test_a_dish_left_behind_by_an_old_section_delete_is_off_the_menu(): void
    {
        // What a category delete did before doc 77: the section soft-deleted, its dishes not.
        $this->mains->delete();
        $customer = $this->makeCustomer();
        $dish = $this->dishes[0];

        $this->actingAsCustomer($customer)->getJson("/api/v1/restaurants/{$this->grill->id}/products")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsCustomer($customer)->getJson("/api/v1/products/{$dish->id}")->assertNotFound();
        $this->actingAsCustomer($customer)
            ->postJson('/api/v1/cart/items', ['product_id' => $dish->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonPath('error', 'product_unavailable');
    }

    public function test_a_place_deletes_many_dishes_at_once_and_only_its_own(): void
    {
        $other = $this->makeRestaurant('مكان آخر');
        $foreign = $this->makeProduct($other, 'ليس لك', 50);

        $this->actingAsPlace($this->grill)
            ->postJson('/api/v1/restaurant/products/bulk-delete', ['ids' => [$this->dishes[0]->id, $this->dishes[1]->id, $foreign->id]])
            ->assertOk()
            ->assertJsonPath('data.deleted', 2);

        $this->assertSoftDeleted($this->dishes[0]);
        $this->assertSoftDeleted($this->dishes[1]);
        $this->assertNotSoftDeleted($this->dishes[2]);
        $this->assertNotSoftDeleted($foreign);
    }

    public function test_bulk_availability_takes_the_name_the_dashboard_sent(): void
    {
        $this->actingAsPlace($this->grill)
            ->postJson('/api/v1/restaurant/products/bulk-availability', ['product_ids' => [$this->dishes[0]->id], 'is_available' => false])
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertFalse($this->dishes[0]->fresh()->is_available);
    }

    public function test_a_place_orders_its_sections_and_the_dishes_inside_one(): void
    {
        $this->actingAsPlace($this->grill)
            ->putJson('/api/v1/restaurant/categories/order', ['ids' => [$this->drinks->id, $this->mains->id]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->drinks->id);
        $this->assertSame(1, $this->mains->fresh()->sort_order);

        $ids = [$this->dishes[2]->id, $this->dishes[0]->id, $this->dishes[1]->id];
        $this->actingAsPlace($this->grill)->putJson("/api/v1/restaurant/categories/{$this->mains->id}/products/order", ['ids' => $ids])->assertOk();
        $this->assertSame(0, $this->dishes[2]->fresh()->sort_order);
        $this->assertSame(2, $this->dishes[1]->fresh()->sort_order);

        // A partial list is refused: it would leave two rows on the same number.
        $this->actingAsPlace($this->grill)
            ->putJson("/api/v1/restaurant/categories/{$this->mains->id}/products/order", ['ids' => [$this->dishes[0]->id]])
            ->assertStatus(422);
    }

    public function test_an_admin_in_scope_has_the_same_control(): void
    {
        $admin = $this->cityAdmin($this->city);
        $base = "/api/v1/admin/restaurants/{$this->grill->id}";

        $this->actingAsAdmin($admin)->deleteJson("{$base}/categories/{$this->mains->id}")->assertStatus(409)->assertJsonPath('error', 'category_not_empty');
        $this->actingAsAdmin($admin)->deleteJson("{$base}/categories/{$this->mains->id}", ['move_to' => $this->drinks->id])->assertOk()->assertJsonPath('data.moved', 3);

        $this->actingAsAdmin($admin)->putJson("{$base}/categories/order", ['ids' => [$this->drinks->id]])->assertOk();
        $ids = [$this->dishes[1]->id, $this->dishes[0]->id, $this->dishes[2]->id];
        $this->actingAsAdmin($admin)->putJson("{$base}/categories/{$this->drinks->id}/products/order", ['ids' => $ids])->assertOk();
        $this->assertSame(0, $this->dishes[1]->fresh()->sort_order);

        $this->actingAsAdmin($admin)->postJson("{$base}/products/bulk-availability", ['ids' => [$this->dishes[0]->id], 'is_available' => false])->assertOk();
        $this->assertFalse($this->dishes[0]->fresh()->is_available);

        $this->actingAsAdmin($admin)->postJson("{$base}/products/bulk-delete", ['ids' => [$this->dishes[0]->id]])->assertOk()->assertJsonPath('data.deleted', 1);
        $this->assertSoftDeleted($this->dishes[0]);
    }

    public function test_an_admin_out_of_scope_gets_404_and_a_look_only_admin_reads_but_cannot_delete(): void
    {
        $elsewhere = $this->cityAdmin($this->otherCity, '01000000077');
        $base = "/api/v1/admin/restaurants/{$this->grill->id}";

        $this->actingAsAdmin($elsewhere)->postJson("{$base}/products/bulk-delete", ['ids' => [$this->dishes[0]->id]])->assertNotFound();
        $this->actingAsAdmin($elsewhere)->deleteJson("{$base}/categories/{$this->drinks->id}")->assertNotFound();
        $this->assertNotSoftDeleted($this->dishes[0]);

        $viewer = $this->adminWith(['products.view']);
        $this->actingAsAdmin($viewer)->getJson("{$base}/categories")->assertOk()->assertJsonCount(2, 'data');
        $this->actingAsAdmin($viewer)->postJson("{$base}/products/bulk-delete", ['ids' => [$this->dishes[0]->id]])->assertForbidden();
        $this->actingAsAdmin($viewer)->deleteJson("{$base}/categories/{$this->drinks->id}")->assertForbidden();
        $this->actingAsAdmin($viewer)->putJson("{$base}/categories/order", ['ids' => [$this->mains->id, $this->drinks->id]])->assertForbidden();
    }

    /** @param  list<string>  $permissions */
    private function adminWith(array $permissions): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'menu_viewer', 'guard_name' => 'web'], ['label_ar' => 'مشاهد', 'label_en' => 'Viewer']);
        $role->syncPermissions(Permission::query()->whereIn('name', $permissions)->pluck('name')->all());

        $admin = User::query()->create(['name' => 'مشاهد', 'phone' => '01000000066', 'type' => 'admin', 'is_active' => true]);
        $admin->assignRole('menu_viewer');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $admin;
    }
}
