<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Media\OwnImages;
use App\Support\Media\ProductGallery;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 72 — the product gallery, written safely.
 *
 * Two write paths share one rule set: the old replace path (`PATCH /products/{id}` with
 * `gallery[]` / `gallery_media[]`, kept for every existing client) and the per-picture API
 * (`POST|DELETE|PUT …/gallery`). Neither may lose a picture to a stale client, reorder
 * silently, exceed the cap, or leave a dropped file on disk — and neither may delete a file
 * the library or another field still shows.
 */
class ProductGalleryTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
        Storage::fake('public');
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    private function admin(string $role = 'super_admin', bool $global = true): static
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole($role);

        if ($global) {
            $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        }

        Sanctum::actingAs($admin);

        return $this->withHeaders(['X-App' => 'admin']);
    }

    private function place(Restaurant $restaurant): static
    {
        Sanctum::actingAs($restaurant->owner);

        return $this->withHeaders(['X-App' => 'restaurant']);
    }

    /** A stored picture on the uploads disk → its URL. */
    private function stored(string $folder = 'products'): string
    {
        $path = $folder.'/gallery-'.(++$this->n).'.jpg';
        Storage::disk('public')->put($path, 'bytes-'.$this->n);

        return Storage::disk('public')->url($path);
    }

    private function pathOf(string $url): string
    {
        return ltrim(substr($url, strlen(rtrim(Storage::disk('public')->url(''), '/'))), '/');
    }

    private function exists(string $url): bool
    {
        return Storage::disk('public')->exists($this->pathOf($url));
    }

    /** @param  list<string>  $urls */
    private function productWith(Restaurant $place, array $urls): Product
    {
        $product = $this->makeProduct($place, 'برجر '.(++$this->n), 50);
        $product->update(['gallery' => $urls]);

        return $product;
    }

    /** @return list<string> */
    private function gallery(Product $product): array
    {
        return array_values((array) $product->fresh()->gallery);
    }

    private function ref(string $url): string
    {
        return OwnImages::ref($url);
    }

    private function libraryAsset(): MediaAsset
    {
        $n = ++$this->n;
        $path = "media-library/lib-{$n}.jpg";
        Storage::disk('public')->put($path, "lib-{$n}");

        return MediaAsset::create([
            'name_ar' => "صورة {$n}", 'name_en' => "image {$n}", 'filename' => "lib-{$n}.jpg",
            'disk' => 'public', 'path' => $path, 'mime' => 'image/jpeg', 'bytes' => 5,
            'sha1' => sha1("lib-{$n}"), 'source' => MediaAsset::SOURCE_UPLOADED,
            'usage_types' => ['product'], 'visibility' => 'global',
        ]);
    }

    private function patchProduct(Restaurant $place, Product $product, array $body): TestResponse
    {
        return $this->place($place)->patchJson("/api/v1/restaurant/products/{$product->id}", $body);
    }

    // ── step 1: the replace path, hardened ──────────────────────────────────────

    public function test_replace_keeps_the_order_sent_refs_first_then_uploads(): void
    {
        $place = $this->makeRestaurant('مطعم');
        [$a, $b, $c] = [$this->stored(), $this->stored(), $this->stored()];
        $product = $this->productWith($place, [$a, $b, $c]);

        $response = $this->patchProduct($place, $product, [
            'gallery_media' => [$this->ref($c), $this->ref($a)],
            'gallery' => [UploadedFile::fake()->image('new.jpg', 60, 60)],
        ])->assertOk();

        $stored = $this->gallery($product);
        $this->assertCount(3, $stored);
        $this->assertSame([$c, $a], array_slice($stored, 0, 2), 'refs in the order given');
        $this->assertNotContains($b, $stored);
        $this->assertSame($stored, $response->json('data.gallery'));
    }

    public function test_replace_stores_a_picture_named_twice_once(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $a = $this->stored();
        $product = $this->productWith($place, [$a]);
        $asset = $this->libraryAsset();

        $this->patchProduct($place, $product, [
            'gallery_media' => [$this->ref($a), $this->ref($a), 'asset:'.$asset->id, 'asset:'.$asset->id],
        ])->assertOk();

        $this->assertSame([$a, $asset->url], $this->gallery($product));
    }

    public function test_replace_over_the_cap_is_a_validation_error_and_changes_nothing(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $urls = [];

        for ($i = 0; $i < Product::GALLERY_MAX; $i++) {
            $urls[] = $this->stored();
        }

        $product = $this->productWith($place, $urls);
        $refs = array_map(fn ($u) => $this->ref($u), $urls);

        // Exactly the cap is fine.
        $this->patchProduct($place, $product, ['gallery_media' => $refs])->assertOk();

        // Refs alone over the cap.
        $this->patchProduct($place, $product, ['gallery_media' => [...$refs, $this->ref($this->stored())]])
            ->assertStatus(422)->assertJsonPath('error', 'validation_failed');

        // Refs plus files over the cap.
        $response = $this->patchProduct($place, $product, [
            'gallery_media' => array_slice($refs, 0, 11),
            'gallery' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ])->assertStatus(422);
        $this->assertSame('validation_failed', $response->json('error'));
        $this->assertArrayHasKey('gallery', $response->json('errors'));

        $this->assertSame($urls, $this->gallery($product), 'a refused write changes nothing');
    }

    public function test_gallery_media_is_validated(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $product = $this->productWith($place, []);

        $this->patchProduct($place, $product, ['gallery_media' => 'asset:1'])->assertStatus(422);
        $this->patchProduct($place, $product, ['gallery_media' => [['x']]])->assertStatus(422);
        $this->patchProduct($place, $product, ['gallery_media' => [str_repeat('a', 2049)]])->assertStatus(422);

        $response = $this->patchProduct($place, $product, ['gallery_media' => ['garbage']])->assertStatus(422);
        $this->assertSame('media_not_allowed', $response->json('error'));
    }

    public function test_the_admin_requests_state_the_same_limits(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $product = $this->productWith($place, []);
        $refs = [];

        for ($i = 0; $i <= Product::GALLERY_MAX; $i++) {
            $refs[] = 'asset:'.($i + 1);
        }

        $this->admin()->patchJson("/api/v1/admin/restaurants/{$place->id}/products/{$product->id}", ['gallery_media' => $refs])
            ->assertStatus(422)->assertJsonPath('error', 'validation_failed');

        $category = $product->menuCategory;
        $this->admin()->postJson("/api/v1/admin/restaurants/{$place->id}/products", [
            'menu_category_id' => $category->id, 'name_ar' => 'س', 'name_en' => 's', 'price' => 10, 'gallery_media' => $refs,
        ])->assertStatus(422)->assertJsonPath('error', 'validation_failed');

        $this->place($place)->postJson('/api/v1/restaurant/products', [
            'menu_category_id' => $category->id, 'name_ar' => 'س', 'name_en' => 's', 'price' => 10, 'gallery_media' => $refs,
        ])->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    }

    public function test_create_stores_the_gallery_in_the_order_sent(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $a = $this->stored();
        $this->productWith($place, [$a]);
        $category = $place->menuCategories()->first();
        $asset = $this->libraryAsset();

        $response = $this->place($place)->post('/api/v1/restaurant/products', [
            'menu_category_id' => $category->id, 'name_ar' => 'جديد', 'name_en' => 'new', 'price' => 30,
            'gallery_media' => [$this->ref($a), 'asset:'.$asset->id],
            'gallery' => [UploadedFile::fake()->image('n.jpg', 50, 50)],
        ], ['Accept' => 'application/json'])->assertCreated();

        $gallery = $response->json('data.gallery');
        $this->assertCount(3, $gallery);
        $this->assertSame([$a, $asset->url], array_slice($gallery, 0, 2));
        $this->assertCount(3, $response->json('data.gallery_items'));
    }

    public function test_files_a_replace_drops_are_deleted_after_the_commit_but_library_and_shared_ones_survive(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $keep = $this->stored();
        $dropped = $this->stored();
        $shared = $this->stored();
        $asset = $this->libraryAsset();
        $product = $this->productWith($place, [$keep, $dropped, $shared, $asset->url]);

        // Another product still shows `$shared`.
        $this->productWith($place, [$shared]);
        // The dropped product's own `image` shows `$dropped`'s sibling — not applicable; but
        // the main image column also counts as a holder:
        $imageHeld = $this->stored();
        $product->update(['gallery' => [$keep, $dropped, $shared, $asset->url, $imageHeld], 'image' => $imageHeld]);

        $this->patchProduct($place, $product, ['gallery_media' => [$this->ref($keep)]])->assertOk();

        $this->assertSame([$keep], $this->gallery($product));
        $this->assertTrue($this->exists($keep));
        $this->assertFalse($this->exists($dropped), 'an orphan is removed');
        $this->assertTrue($this->exists($shared), 'another product shows it');
        $this->assertTrue(Storage::disk('public')->exists($asset->path), 'the library file survives');
        $this->assertTrue($this->exists($imageHeld), 'the product image still shows it');
    }

    public function test_remove_gallery_empties_it_and_deletes_the_files(): void
    {
        $place = $this->makeRestaurant('مطعم');
        [$a, $b] = [$this->stored(), $this->stored()];
        $product = $this->productWith($place, [$a, $b]);

        $this->patchProduct($place, $product, ['remove_gallery' => true])->assertOk();

        $this->assertSame([], $this->gallery($product));
        $this->assertFalse($this->exists($a));
        $this->assertFalse($this->exists($b));
    }

    public function test_an_empty_gallery_key_no_longer_wipes_the_stored_gallery(): void
    {
        $place = $this->makeRestaurant('مطعم');
        [$a, $b] = [$this->stored(), $this->stored()];
        $product = $this->productWith($place, [$a, $b]);

        $this->patchProduct($place, $product, ['gallery' => null, 'name_ar' => 'اسم جديد'])->assertOk();

        $this->assertSame([$a, $b], $this->gallery($product));
        $this->assertTrue($this->exists($a));
    }

    public function test_a_patch_that_names_no_gallery_leaves_it_alone(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $a = $this->stored();
        $product = $this->productWith($place, [$a]);

        $this->patchProduct($place, $product, ['price' => 77])->assertOk();

        $this->assertSame([$a], $this->gallery($product));
        $this->assertSame('77.00', $product->fresh()->price);
    }

    public function test_a_stale_expected_version_on_the_replace_path_is_refused_and_no_file_is_left_behind(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $a = $this->stored();
        $product = $this->productWith($place, [$a]);
        $stale = ProductGallery::version([$this->stored()]);
        $before = Storage::disk('public')->allFiles('products');

        $response = $this->patchProduct($place, $product, [
            'expected_version' => $stale,
            'gallery' => [UploadedFile::fake()->image('x.jpg', 50, 50)],
        ])->assertStatus(409);

        $this->assertSame('gallery_changed', $response->json('error'));
        $this->assertSame(ProductGallery::version([$a]), $response->json('meta.gallery_version'));
        $this->assertSame($a, $response->json('meta.gallery_items.0.url'));
        $this->assertSame([$a], $this->gallery($product));
        $this->assertEqualsCanonicalizing($before, Storage::disk('public')->allFiles('products'), 'nothing was stored');
    }

    public function test_the_current_expected_version_lets_the_replace_through(): void
    {
        $place = $this->makeRestaurant('مطعم');
        [$a, $b] = [$this->stored(), $this->stored()];
        $product = $this->productWith($place, [$a, $b]);

        $this->patchProduct($place, $product, [
            'expected_version' => ProductGallery::version([$a, $b]),
            'gallery_media' => [$this->ref($b), $this->ref($a)],
        ])->assertOk()->assertJsonPath('data.gallery', [$b, $a]);
    }

    public function test_the_admin_replace_path_still_works_and_carries_the_new_fields(): void
    {
        $place = $this->makeRestaurant('مطعم');
        [$a, $b] = [$this->stored(), $this->stored()];
        $product = $this->productWith($place, [$a, $b]);

        $response = $this->admin()->patchJson("/api/v1/admin/restaurants/{$place->id}/products/{$product->id}", [
            'gallery_media' => [$this->ref($b)],
        ])->assertOk();

        $this->assertSame([$b], $response->json('data.gallery'));
        $this->assertSame($this->ref($b), $response->json('data.gallery_items.0.ref'));
        $this->assertFalse($this->exists($a));
    }
}
