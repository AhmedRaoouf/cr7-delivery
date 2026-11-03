<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * doc 99 §8 — D-176: a store category chooses how its places lay out their products —
 * rows for a kitchen, the two-column shelf for a shop.
 */
class ProductLayoutTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();
    }

    private function category(string $slug, ?string $layout = null): StoreCategory
    {
        return StoreCategory::query()->create(array_filter([
            'slug' => $slug, 'name_ar' => $slug, 'name_en' => $slug, 'is_active' => true, 'product_layout' => $layout,
        ]));
    }

    public function test_the_shops_start_on_the_grid_and_the_kitchens_on_rows(): void
    {
        foreach (StoreCategory::GRID_SLUGS as $slug) {
            $this->assertSame('grid', $this->category($slug)->fresh()->product_layout, $slug);
        }

        foreach (['restaurants', 'cafes', 'desserts', 'dry-clean'] as $slug) {
            $this->assertSame('list', $this->category($slug)->fresh()->product_layout, $slug);
        }

        // An explicit choice wins over the slug.
        $this->assertSame('list', $this->category('pharmacies-2', 'list')->product_layout);
    }

    public function test_the_migration_set_the_grid_for_the_five_slugs(): void
    {
        // The column default is `list`; the migration's data default (`applyDefaultLayouts`)
        // is what the rows already on the database get on deploy.
        DB::table('store_categories')->insert([
            ['slug' => 'supermarkets', 'name_ar' => 'س', 'name_en' => 's', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'cafes', 'name_ar' => 'ك', 'name_en' => 'c', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->assertSame('list', DB::table('store_categories')->where('slug', 'supermarkets')->value('product_layout'));

        $this->assertSame(1, StoreCategory::applyDefaultLayouts());

        $this->assertSame('grid', DB::table('store_categories')->where('slug', 'supermarkets')->value('product_layout'));
        $this->assertSame('list', DB::table('store_categories')->where('slug', 'cafes')->value('product_layout'));
    }

    public function test_the_layout_is_published_on_the_strip_and_on_every_place(): void
    {
        $pharmacies = $this->category('pharmacies');
        $place = $this->makeRestaurant('صيدلية حجازي');
        $place->forceFill(['store_category_id' => $pharmacies->id])->save();
        $kitchen = $this->makeRestaurant('سندوتش عمر');

        $strip = collect($this->getJson('/api/v1/store-categories')->assertOk()->json('data'))->firstWhere('slug', 'pharmacies');
        $this->assertSame('grid', $strip['product_layout']);

        $this->getJson('/api/v1/restaurants/'.$place->id)->assertOk()->assertJsonPath('data.product_layout', 'grid');
        $this->getJson('/api/v1/restaurants/'.$kitchen->id)->assertOk()->assertJsonPath('data.product_layout', 'list');

        $cards = collect($this->getJson('/api/v1/restaurants')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('grid', $cards[$place->id]['product_layout']);
        $this->assertSame('list', $cards[$kitchen->id]['product_layout']);

        $search = $this->getJson('/api/v1/search?'.http_build_query(['search' => 'صيدلية']))->assertOk();
        $this->assertSame('grid', $search->json('data.restaurants.0.product_layout'));
    }

    public function test_an_admin_changes_it_and_the_app_follows(): void
    {
        $cafes = $this->category('cafes');
        $place = $this->makeRestaurant('ليتو');
        $place->forceFill(['store_category_id' => $cafes->id])->save();

        $this->getJson('/api/v1/restaurants/'.$place->id)->assertJsonPath('data.product_layout', 'list');

        $admin = $this->globalAdmin();
        $this->actingAsAdmin($admin)->patchJson("/api/v1/admin/store-categories/{$cafes->id}", ['product_layout' => 'grid'])
            ->assertOk()->assertJsonPath('data.product_layout', 'grid');

        // The save bumped the catalogue: the cached detail is not served stale.
        $this->getJson('/api/v1/restaurants/'.$place->id)->assertJsonPath('data.product_layout', 'grid');

        $this->actingAsAdmin($admin)->patchJson("/api/v1/admin/store-categories/{$cafes->id}", ['product_layout' => 'mosaic'])
            ->assertStatus(422)->assertJsonValidationErrors(['product_layout']);
        // A cleared field keeps what is stored.
        $this->actingAsAdmin($admin)->patchJson("/api/v1/admin/store-categories/{$cafes->id}", ['product_layout' => null])
            ->assertOk()->assertJsonPath('data.product_layout', 'grid');

        $this->actingAsAdmin($admin)->postJson('/api/v1/admin/store-categories', ['name_ar' => 'هدايا', 'name_en' => 'Gifts', 'product_layout' => 'grid'])
            ->assertCreated()->assertJsonPath('data.product_layout', 'grid');
    }
}
