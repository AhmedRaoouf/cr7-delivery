<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use Database\Seeders\CuisineArtworkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `CUS-12`'s chips are photographs, and nine of them were not.
 *
 * `catalog.json` gained the nine paths after the rows existed, and only
 * `CatalogSeeder` reads it — which also rewrites every restaurant and menu on
 * the deployment. So the artwork stayed null for a week and the filter sheet
 * showed six photographs above nine fallback marks, some of them repeats.
 */
class CuisineArtworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fills_the_artwork_the_catalogue_names(): void
    {
        $cuisine = Cuisine::query()->create([
            'name_ar' => 'معجنات', 'name_en' => 'Pastries',
            'icon' => null, 'image' => null, 'sort_order' => 6, 'is_active' => true,
        ]);

        $this->seed(CuisineArtworkSeeder::class);

        $cuisine->refresh();

        $this->assertStringContainsString('catalog/cuisines/pastry.jpg', (string) $cuisine->image);
        $this->assertSame('pastry', $cuisine->icon);
    }

    public function test_a_cuisine_the_operator_added_keeps_its_own_picture(): void
    {
        $cuisine = Cuisine::query()->create([
            'name_ar' => 'مأكولات نباتية', 'name_en' => 'Vegan',
            'icon' => 'meal', 'image' => 'https://example.test/vegan.jpg',
            'sort_order' => 99, 'is_active' => true,
        ]);

        $this->seed(CuisineArtworkSeeder::class);

        $this->assertSame('https://example.test/vegan.jpg', $cuisine->refresh()->image);
    }
}
