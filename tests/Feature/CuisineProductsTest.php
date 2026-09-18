<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\Product;
use App\Models\Restaurant;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A cuisine is a picture that leads to dishes.
 *
 * Two operator asks, one endpoint. «حط برضه لكل واحدة صورة، ما تبقاش متسبة كده مجرد
 * كلام» — so `GET /cuisines` has to carry artwork; and «لو أدوس على البرجر… يجيبلي
 * المنتجات على طول، مش مطاعم» — so tapping one has to answer with dishes, paginated,
 * each naming the kitchen it came from.
 */
class CuisineProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingsSeeder::class, LocationSeeder::class, ContentSeeder::class, CatalogSeeder::class]);
    }

    /**
     * Every cuisine carries an icon slug, and any artwork it carries is real.
     *
     * **Not «every cuisine has artwork».** Six of the fifteen have a photograph
     * today; the other nine were still queued when ChatGPT's image quota ran out
     * on 2026-09-08, and their `image` is deliberately null rather than pointing
     * at a file that is not there — a null falls back to the icon the cuisine
     * already has, a dead path draws the brand watermark and fails
     * `CatalogSeedTest`. When the rest are generated the seed fills them in and
     * this keeps passing; nothing here has to change.
     */
    public function test_every_cuisine_ships_with_an_icon_and_real_artwork(): void
    {
        $response = $this->getJson('/api/v1/cuisines');

        $response->assertOk();
        $rows = $response->json('data');

        $this->assertNotEmpty($rows);

        $withArtwork = 0;

        foreach ($rows as $row) {
            // Both names travel — the app caches this list and reads it under whichever
            // locale is on screen (CLAUDE.md §6).
            $this->assertArrayHasKey('name_ar', $row);
            $this->assertArrayHasKey('name_en', $row);
            $this->assertNotNull($row['icon'], "{$row['name_ar']} has no icon slug");

            if ($row['image'] === null) {
                continue;
            }

            $withArtwork++;
            $this->assertStringContainsString(
                "catalog/cuisines/{$row['icon']}.jpg",
                $row['image'],
                'the seeded artwork is addressed by the icon slug',
            );
            $this->assertFileExists(
                public_path(parse_url($row['image'], PHP_URL_PATH) ?? ''),
                "{$row['name_ar']} points at artwork that is not on disk",
            );
        }

        $this->assertGreaterThan(0, $withArtwork, 'not one cuisine has artwork');
    }

    public function test_a_cuisine_answers_with_dishes_rather_than_restaurants(): void
    {
        $cuisine = $this->cuisineWithProducts();

        $response = $this->getJson("/api/v1/cuisines/{$cuisine->id}/products?per_page=5");

        $response->assertOk();
        $rows = $response->json('data');

        $this->assertCount(5, $rows);
        // Hand-rolled pagination, not Laravel's envelope (CLAUDE.md §6).
        $this->assertSame(1, $response->json('meta.current_page'));
        $this->assertSame(5, $response->json('meta.per_page'));
        $this->assertGreaterThan(5, $response->json('meta.total'));

        $expected = Restaurant::query()
            ->whereHas('cuisines', fn ($q) => $q->where('cuisines.id', $cuisine->id))
            ->pluck('id')
            ->all();

        foreach ($rows as $row) {
            // A dish, not a shop: a restaurant row has `rating_count` and no price.
            $this->assertArrayHasKey('price', $row);
            $this->assertArrayHasKey('menu_category_id', $row);
            $this->assertContains($row['restaurant_id'], $expected);
            // Out of its own menu a dish is unopenable and unaddable without this.
            $this->assertNotNull($row['restaurant_name']);
            $this->assertTrue($row['is_available']);
        }
    }

    public function test_the_pages_partition_the_list_rather_than_overlapping(): void
    {
        $cuisine = $this->cuisineWithProducts();

        $first = $this->getJson("/api/v1/cuisines/{$cuisine->id}/products?per_page=10");
        $second = $this->getJson("/api/v1/cuisines/{$cuisine->id}/products?per_page=10&page=2");

        $first->assertOk();
        $second->assertOk();

        $ids = array_merge(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
        );

        $this->assertSame(
            count($ids),
            count(array_unique($ids)),
            'a dish appeared on two pages — the sort has no stable tiebreak',
        );
        $this->assertSame(2, $second->json('meta.current_page'));
    }

    public function test_an_unavailable_dish_is_not_listed(): void
    {
        $cuisine = $this->cuisineWithProducts();
        $listed = $this->getJson("/api/v1/cuisines/{$cuisine->id}/products?per_page=100");
        $first = $listed->json('data.0.id');

        // Through the model, as every screen that switches a dish off does: the public
        // catalogue is cached since batch 68 and a model write is what retires it. A raw
        // SQL update is picked up on the cache's TTL instead.
        Product::query()->findOrFail($first)->update(['is_available' => false]);

        $again = $this->getJson("/api/v1/cuisines/{$cuisine->id}/products?per_page=100");

        $this->assertNotContains($first, array_column($again->json('data'), 'id'));
    }

    public function test_a_hidden_cuisine_is_a_404_rather_than_an_empty_list(): void
    {
        $cuisine = $this->cuisineWithProducts();
        $cuisine->update(['is_active' => false]);

        $this->getJson("/api/v1/cuisines/{$cuisine->id}/products")->assertNotFound();
        // And it drops out of the chip list entirely.
        $this->assertNotContains(
            $cuisine->id,
            array_column($this->getJson('/api/v1/cuisines')->json('data'), 'id'),
        );
    }

    /** A cuisine doc 17 actually tagged a kitchen with, so the assertions have rows. */
    private function cuisineWithProducts(): Cuisine
    {
        $cuisine = Cuisine::query()
            ->whereHas('restaurants', fn ($q) => $q->where('is_active', true)->whereHas('products'))
            ->orderBy('sort_order')
            ->first();

        $this->assertNotNull($cuisine, 'the seeded catalogue tags no kitchen with a cuisine');

        return $cuisine;
    }
}
