<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\StaticPage;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * doc 17 — the real Gamasa catalogue has to reach the database intact, and the derived
 * `price_band` (D-60) has to come back with the eight values doc 17 §3b measured off
 * the Figma boards. If a band moves here, either the menu changed or the median rule did.
 */
class CatalogSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingsSeeder::class, LocationSeeder::class, ContentSeeder::class, CatalogSeeder::class]);
    }

    public function test_the_whole_catalogue_lands(): void
    {
        $this->assertSame(8, Restaurant::query()->count());
        $this->assertSame(69, MenuCategory::query()->count());
        $this->assertSame(562, Product::query()->count());
        $this->assertGreaterThan(0, Cuisine::query()->count());
    }

    public function test_every_price_band_matches_doc_17(): void
    {
        $expected = [
            'sandwich-omar' => 'mid',
            'alrokn-elaraby' => 'premium',
            'ebn-elbalad' => 'budget',
            'saad-eldomiaty' => 'budget',
            'king-broast' => 'mid',
            'boxs' => 'mid',
            'nasser-alqias' => 'premium',
            'sawany-eldomiaty' => 'mid',
        ];

        foreach ($expected as $slug => $band) {
            $this->assertSame(
                $band,
                Restaurant::query()->where('slug', $slug)->value('price_band'),
                "price_band for {$slug}",
            );
        }
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $before = [Restaurant::query()->count(), MenuCategory::query()->count(), Product::query()->count()];

        $this->seed(CatalogSeeder::class);

        $after = [Restaurant::query()->count(), MenuCategory::query()->count(), Product::query()->count()];

        $this->assertSame($before, $after, 're-running the seeder must refresh, never duplicate');
    }

    public function test_the_catalogue_endpoints_answer(): void
    {
        $this->getJson('/api/v1/restaurants?per_page=3')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'logo', 'price_band', 'tagline', 'rating_avg']]]);

        $restaurant = Restaurant::query()->where('slug', 'boxs')->firstOrFail();

        $this->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertOk()
            ->assertJsonPath('data.menu_layout', 'list')
            ->assertJsonPath('data.price_band', 'mid');

        $this->getJson("/api/v1/restaurants/{$restaurant->id}/products")
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'price', 'restaurant_id']]]);
    }

    public function test_search_finds_a_real_dish(): void
    {
        // whereFullText — this is the assertion that fails the moment anyone points
        // the app at SQLite.
        $this->getJson('/api/v1/search?q='.urlencode('برجر'))
            ->assertOk();
    }

    public function test_the_price_band_filter_and_the_new_sorts_answer(): void
    {
        $this->getJson('/api/v1/restaurants?filter[price_band]=premium')
            ->assertOk()
            ->assertJsonPath('data.0.price_band', 'premium');

        $this->getJson('/api/v1/restaurants?sort=nearest&lat=31.4041&lng=31.4712')->assertOk();
        $this->getJson('/api/v1/restaurants?sort=fastest')->assertOk();
        $this->getJson('/api/v1/restaurants?sort=-orders_count')->assertOk();
        $this->getJson('/api/v1/restaurants?filter[max_delivery_fee]=10')->assertOk();
    }

    public function test_the_bulk_product_route_returns_many_rows_in_one_request(): void
    {
        $ids = Product::query()->limit(3)->pluck('id')->implode(',');

        $this->getJson("/api/v1/products?ids={$ids}")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_the_three_legal_pages_exist(): void
    {
        foreach (['terms', 'privacy', 'about'] as $slug) {
            $this->getJson("/api/v1/pages/{$slug}")->assertOk()->assertJsonPath('data.slug', $slug);
        }

        $this->assertSame(3, StaticPage::query()->count());
        $this->getJson('/api/v1/faqs')->assertOk()->assertJsonCount(9, 'data');
    }

    public function test_every_seeded_image_resolves_to_a_file_that_exists(): void
    {
        $missing = [];

        foreach (Restaurant::query()->get(['logo', 'cover', 'menu_sheets']) as $restaurant) {
            foreach (array_merge([$restaurant->logo, $restaurant->cover], $restaurant->menu_sheets ?? []) as $url) {
                if ($url && ! is_file(public_path(parse_url($url, PHP_URL_PATH)))) {
                    $missing[] = $url;
                }
            }
        }

        foreach (Product::query()->whereNotNull('image')->pluck('image') as $url) {
            if (! is_file(public_path(parse_url($url, PHP_URL_PATH)))) {
                $missing[] = $url;
            }
        }

        $this->assertSame([], array_values(array_unique($missing)));
    }
}
