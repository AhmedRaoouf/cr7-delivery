<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\StaticPage;
use App\Models\StoreCategory;
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

    /**
     * 22 shops.
     *
     * It was 8 — the restaurants doc 17 transcribed — then 11 on 2026-09-07, when
     * «صيدليات» and «سوبرماركت» each needed something behind the card or it led
     * nowhere. It is 22 on 2026-09-08 because one shop behind a card is not a
     * section: the operator asked for «تلاتة أربعة صيدليات، تلاتة أربعة خضروات
     * وفاكهة» so that filtering to a category returns a list rather than a single
     * row. Four of the five cards now hold three or more.
     */
    public function test_the_whole_catalogue_lands(): void
    {
        $this->assertSame(22, Restaurant::query()->count());
        $this->assertSame(124, MenuCategory::query()->count());
        $this->assertSame(846, Product::query()->count());
        $this->assertGreaterThan(0, Cuisine::query()->count());
    }

    public function test_every_shop_lands_in_a_store_category(): void
    {
        $this->assertSame(5, StoreCategory::query()->count());

        // Not one shop may be left without a card to appear under — that is precisely
        // how a restaurant vanishes from the home screen and nobody notices for a week.
        $this->assertSame(
            0,
            Restaurant::query()->whereNull('store_category_id')->count(),
            'every seeded shop belongs to a store category',
        );

        $counts = StoreCategory::query()
            ->withCount('restaurants')
            ->pluck('restaurants_count', 'slug')
            ->all();

        // 7, not 8: سعد الدمياطي is «مطعم كافيه» in doc 17 and sits under «كافيهات».
        $this->assertSame(7, $counts['restaurants']);
        $this->assertSame(4, $counts['cafes']);
        $this->assertSame(4, $counts['pharmacies']);
        $this->assertSame(3, $counts['supermarkets']);
        $this->assertSame(4, $counts['greengrocers']);

        // The point of the 2026-09-08 batch: no card leads to a list of one.
        foreach ($counts as $slug => $count) {
            $this->assertGreaterThanOrEqual(3, $count, "«{$slug}» needs more than a shop or two behind it");
        }
    }

    public function test_the_public_strip_lists_every_active_category(): void
    {
        $this->getJson('/api/v1/store-categories')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.slug', 'restaurants')
            ->assertJsonPath('data.0.name_ar', 'مطاعم')
            ->assertJsonPath('data.4.restaurants_count', 4);
    }

    public function test_a_shop_can_be_browsed_by_its_store_category(): void
    {
        $this->getJson('/api/v1/restaurants?filter[store_category]=pharmacies')
            ->assertOk()
            ->assertJsonCount(4, 'data');

        $this->getJson('/api/v1/restaurants?filter[store_category]=supermarkets')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        // Several at once, comma-separated: the home cards are a multi-select since
        // 2026-09-08 and the server ORs them. 4 + 3, and not one row twice.
        $response = $this->getJson('/api/v1/restaurants?filter[store_category]=pharmacies,supermarkets')
            ->assertOk()
            ->assertJsonCount(7, 'data');

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame($ids, array_values(array_unique($ids)), 'an OR filter must not duplicate a row');

        // An unknown slug is an empty list, never every shop — a filter that
        // silently stops filtering is worse than one that returns nothing.
        $this->getJson('/api/v1/restaurants?filter[store_category]=pharmacies,nonsense')
            ->assertOk()
            ->assertJsonCount(4, 'data');
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

    /**
     * Replacing artwork at a path that already exists has to change the URL.
     *
     * The app keeps every remote image on disk for thirty days keyed on its URL, so a
     * regenerated picture served from the same path is invisible on any phone that has
     * already seen the old one. On 2026-09-08 «مطاعم» was replaced, the server served
     * the new file, and the app kept drawing the empty plate it had cached — nothing
     * anywhere reported a fault.
     *
     * The stamp is derived from the file, so an unchanged one keeps its URL and a
     * re-seed does not invalidate the whole catalogue.
     */
    public function test_seeded_artwork_carries_a_stamp_that_changes_with_the_file(): void
    {
        $cover = Restaurant::query()->whereNotNull('cover')->value('cover');

        $this->assertNotNull($cover);
        $this->assertMatchesRegularExpression('/\?v=[0-9a-f]{8}$/', $cover);

        $path = public_path(parse_url($cover, PHP_URL_PATH));
        parse_str((string) parse_url($cover, PHP_URL_QUERY), $query);

        $this->assertSame(
            substr(md5(filemtime($path).':'.filesize($path)), 0, 8),
            $query['v'],
            'the stamp must come from the file, not from the seed run',
        );
    }
}
