<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * doc 08 §7 — «All admin list endpoints accept the global filters `filter[city_id]`,
 * `filter[zone_id]`, `filter[date_from]`, `filter[date_to]`».
 *
 * Half of them read the bare name instead, and both dashboards send the documented
 * spelling. The result was the worst kind of bug to find by hand: **every search box and
 * city picker on those screens did nothing**. No error, no empty list — the same
 * unfiltered page came back, which looks exactly like «that is all the data there is».
 *
 * These tests are spelling tests. They exist because the defect was invisible to types,
 * to lint and to a 200, and would come back the moment a new list endpoint is written
 * from the wrong neighbour.
 */
class ListFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
    }

    public function test_the_restaurant_search_box_narrows_the_list(): void
    {
        $this->makeRestaurant('سندوتش عمر');
        $this->makeRestaurant('كنج بروست');
        Sanctum::actingAs($this->makeAdmin());

        $all = $this->admin('/api/v1/admin/restaurants', ['per_page' => 50]);
        $this->assertCount(2, $all);

        // The documented spelling.
        $filtered = $this->admin('/api/v1/admin/restaurants', ['per_page' => 50, 'filter' => ['search' => 'بروست']]);
        $this->assertCount(1, $filtered);
        $this->assertSame('كنج بروست', $filtered[0]['name_ar']);

        // And the bare one, which `GET /restaurants` publishes to the customer app
        // (doc 08 §4). Removing the fallback would fix a dashboard and break a phone.
        $this->assertCount(1, $this->admin('/api/v1/admin/restaurants', ['per_page' => 50, 'search' => 'بروست']));
    }

    public function test_a_partial_word_matches(): void
    {
        $this->makeRestaurant('سندوتش عمر');
        Sanctum::actingAs($this->makeAdmin());

        // This is why the search is `like` and not `whereFullText`: a full-text index
        // answers on whole words, and a search box fires on every keystroke.
        $this->assertCount(1, $this->admin('/api/v1/admin/restaurants', ['filter' => ['search' => 'سندو']]));
    }

    public function test_the_captain_search_reaches_the_name_on_the_user_row(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->makeDriver('محمود سعيد', '01011112222');
        $this->makeDriver('يوسف كامل', '01033334444');

        $this->assertCount(2, $this->admin('/api/v1/admin/drivers', ['per_page' => 50]));

        // `ADM-19` has had this box since it was drawn and the endpoint had no filter at
        // all, so a search returned the whole fleet.
        $this->assertCount(1, $this->admin('/api/v1/admin/drivers', ['filter' => ['search' => 'يوسف']]));
        // The phone is the other thing an operator has in front of them.
        $this->assertCount(1, $this->admin('/api/v1/admin/drivers', ['filter' => ['search' => '0101111']]));
        $this->assertCount(0, $this->admin('/api/v1/admin/drivers', ['filter' => ['search' => 'zzzzzz']]));
    }

    public function test_the_product_search_narrows_a_long_menu(): void
    {
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $this->makeProduct($restaurant, 'سندوتش جمبري', 60);
        $this->makeProduct($restaurant, 'أرز بالخلطة', 40);

        Sanctum::actingAs($restaurant->owner);

        $all = $this->restaurant('/api/v1/restaurant/products', ['per_page' => 50]);
        $this->assertCount(2, $all);

        $filtered = $this->restaurant('/api/v1/restaurant/products', ['filter' => ['search' => 'جمبري']]);
        $this->assertCount(1, $filtered);
        $this->assertSame('سندوتش جمبري', $filtered[0]['name_ar']);
    }

    public function test_an_absent_filter_is_not_a_filter_on_the_empty_string(): void
    {
        $this->makeRestaurant('سندوتش عمر');
        Sanctum::actingAs($this->makeAdmin());

        // `?filter[search]=` with nothing after it is what a cleared search box sends.
        // Treating it as a term would hand the operator an empty screen.
        $this->assertCount(1, $this->admin('/api/v1/admin/restaurants', ['filter' => ['search' => '']]));
        $this->assertCount(1, $this->admin('/api/v1/admin/restaurants'));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function admin(string $path, array $query = []): array
    {
        return $this->withHeader('X-App', 'admin')->getJson($this->url($path, $query))->assertOk()->json('data');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function restaurant(string $path, array $query = []): array
    {
        return $this->withHeader('X-App', 'restaurant')->getJson($this->url($path, $query))->assertOk()->json('data');
    }

    /**
     * Built with `http_build_query`, never by hand.
     *
     * A literal `?filter[search]=بروست` in a test reaches the controller as mojibake —
     * the test client does not re-encode a raw UTF-8 query string — and the assertion
     * then fails against correct code, which is a very good way to «fix» a filter that
     * was never broken.
     *
     * @param  array<string, mixed>  $query
     */
    private function url(string $path, array $query): string
    {
        return $query === [] ? $path : $path.'?'.http_build_query($query);
    }

    private function makeDriver(string $name, string $phone): void
    {
        $user = User::query()->create([
            'name' => $name,
            'phone' => $phone,
            'type' => 'driver',
            'is_active' => true,
        ]);

        $user->driver()->create([
            'city_id' => $this->city->id,
            'vehicle_type' => 'motorcycle',
            'is_active' => true,
        ]);
    }

    private function makeAdmin(): User
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'phone' => '01000000099',
            'type' => 'admin',
            'is_active' => true,
        ]);

        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $admin;
    }
}
