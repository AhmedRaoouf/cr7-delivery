<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Catalog\PlaceOrder;
use App\Support\Settings;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Doc 87 — «عايز الادمن يقدر يتحكم في ترتيب الاماكن في التطبيق … دا كله لحد ما اليوزر
 * يعمل فلتر». Pinned places first in the operator's order, the rest by the preset, and a
 * sort the customer chose wins outright.
 */
class PlaceOrderTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $a;

    private Restaurant $b;

    private Restaurant $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);

        $this->a = $this->makeRestaurant('أ');
        $this->b = $this->makeRestaurant('ب');
        $this->c = $this->makeRestaurant('ج');
        $this->a->forceFill(['rating_avg' => 3.0, 'orders_count' => 50])->save();
        $this->b->forceFill(['rating_avg' => 4.9, 'orders_count' => 5])->save();
        $this->c->forceFill(['rating_avg' => 4.0, 'orders_count' => 500])->save();
    }

    private function admin(AdminScopeType $scope = AdminScopeType::Global): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => $scope, 'scope_id' => $scope === AdminScopeType::Global ? null : $this->city->id]);

        return $admin;
    }

    /** @return list<int> */
    private function customerOrder(array $query = [], array $headers = []): array
    {
        $response = $this->withHeaders(['X-App' => 'app'] + $headers)
            ->getJson('/api/v1/restaurants?'.http_build_query($query));
        $response->assertOk();

        return array_column($response->json('data'), 'id');
    }

    public function test_the_preset_orders_the_list_when_the_customer_chose_no_sort(): void
    {
        Settings::set(PlaceOrder::SETTING, 'top_rated', 'catalog');
        $this->assertSame([$this->b->id, $this->c->id, $this->a->id], $this->customerOrder());

        Settings::set(PlaceOrder::SETTING, 'most_ordered', 'catalog');
        $this->assertSame([$this->c->id, $this->a->id, $this->b->id], $this->customerOrder(['sort' => 'recommended']));
    }

    public function test_an_admin_pins_places_and_they_come_first_in_that_order(): void
    {
        Sanctum::actingAs($this->admin());

        $this->withHeader('X-App', 'admin')
            ->putJson('/api/v1/admin/places/order', ['mode' => 'top_rated', 'pinned_ids' => [$this->a->id]])
            ->assertOk()
            ->assertJsonPath('data.mode', 'top_rated')
            ->assertJsonPath('data.places.0.id', $this->a->id)
            ->assertJsonPath('data.places.0.pin_position', 1)
            ->assertJsonPath('data.places.1.pin_position', null);

        // The pin wins over the preset; the rest follow the preset.
        $this->assertSame([$this->a->id, $this->b->id, $this->c->id], $this->customerOrder());

        // Pinning every place is a fully manual list.
        $this->putJson('/api/v1/admin/places/order', [
            'mode' => 'top_rated',
            'pinned_ids' => [$this->c->id, $this->a->id, $this->b->id],
        ])->assertOk();
        $this->assertSame([$this->c->id, $this->a->id, $this->b->id], $this->customerOrder());

        // Unpinning everything hands the list back to the preset.
        $this->putJson('/api/v1/admin/places/order', ['mode' => 'most_ordered', 'pinned_ids' => []])->assertOk();
        $this->assertSame([$this->c->id, $this->a->id, $this->b->id], $this->customerOrder());
        $this->assertSame(0, Restaurant::query()->whereNotNull('pin_position')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'places.order']);
    }

    public function test_a_sort_the_customer_chose_ignores_the_operators_order(): void
    {
        $this->a->forceFill(['pin_position' => 1])->save();

        $this->assertSame([$this->b->id, $this->c->id, $this->a->id], $this->customerOrder(['sort' => '-rating_avg']));
        $this->assertSame([$this->c->id, $this->a->id, $this->b->id], $this->customerOrder(['sort' => 'popular']));
    }

    public function test_an_old_app_reads_its_untouched_nearest_as_the_operators_order(): void
    {
        $this->c->forceFill(['pin_position' => 1])->save();
        $point = ['sort' => 'nearest', 'lat' => 31.3840, 'lng' => 31.4840];

        $this->assertSame($this->c->id, $this->customerOrder($point, ['X-App-Version' => '1.0.21'])[0]);
        // …but not once the customer used the filter sheet.
        $filtered = $this->customerOrder($point + ['filter' => ['min_rating' => 1]], ['X-App-Version' => '1.0.21']);
        $this->assertSame([$this->a->id, $this->b->id, $this->c->id], $filtered);
        // A current app asking for «الأقرب» means it.
        $this->assertSame([$this->a->id, $this->b->id, $this->c->id], $this->customerOrder($point, ['X-App-Version' => '1.0.22']));
    }

    public function test_the_panel_lists_every_place_with_the_modes(): void
    {
        $this->b->forceFill(['is_active' => false])->save();
        Sanctum::actingAs($this->admin());

        $response = $this->withHeader('X-App', 'admin')->getJson('/api/v1/admin/places/order')->assertOk();

        $this->assertCount(3, $response->json('data.places'));
        $this->assertSame(PlaceOrder::values(), array_column($response->json('data.modes'), 'value'));
        $this->assertSame('featured', $response->json('data.mode'));
    }

    public function test_a_bad_write_is_refused_and_changes_nothing(): void
    {
        Sanctum::actingAs($this->admin());

        $this->withHeader('X-App', 'admin')
            ->putJson('/api/v1/admin/places/order', ['mode' => 'nonsense', 'pinned_ids' => [$this->a->id]])
            ->assertUnprocessable();
        $this->putJson('/api/v1/admin/places/order', ['mode' => 'newest', 'pinned_ids' => [$this->a->id, $this->a->id]])
            ->assertUnprocessable();
        $this->putJson('/api/v1/admin/places/order', ['mode' => 'newest', 'pinned_ids' => [999999]])
            ->assertUnprocessable();

        $this->assertSame(0, Restaurant::query()->whereNotNull('pin_position')->count());
    }

    public function test_a_city_scoped_operator_cannot_reorder_the_platform(): void
    {
        Sanctum::actingAs($this->admin(AdminScopeType::City));

        $this->withHeader('X-App', 'admin')->getJson('/api/v1/admin/places/order')->assertNotFound();
        $this->putJson('/api/v1/admin/places/order', ['mode' => 'newest', 'pinned_ids' => []])->assertNotFound();
    }

    public function test_every_mode_runs(): void
    {
        foreach (PlaceOrder::values() as $mode) {
            Settings::set(PlaceOrder::SETTING, $mode, 'catalog');
            $this->assertCount(3, $this->customerOrder(), $mode);
        }
    }
}
