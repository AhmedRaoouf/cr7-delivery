<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\City;
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

    // ── step 2: the per-picture API ─────────────────────────────────────────────

    private function base(Restaurant $place, Product $product): string
    {
        return "/api/v1/restaurant/products/{$product->id}/gallery";
    }

    private function adminBase(Restaurant $place, Product $product): string
    {
        return "/api/v1/admin/restaurants/{$place->id}/products/{$product->id}/gallery";
    }

    public function test_show_and_index_carry_gallery_items_and_a_version_and_the_url_list_is_unchanged(): void
    {
        $place = $this->makeRestaurant('مطعم');
        [$a, $b] = [$this->stored(), $this->stored()];
        $product = $this->productWith($place, [$a, $b]);

        foreach ([
            fn () => $this->place($place)->getJson("/api/v1/restaurant/products/{$product->id}")->json('data'),
            fn () => $this->place($place)->getJson('/api/v1/restaurant/products')->json('data.0'),
            fn () => $this->admin()->getJson("/api/v1/admin/restaurants/{$place->id}/products/{$product->id}")->json('data'),
            fn () => $this->admin()->getJson("/api/v1/admin/restaurants/{$place->id}/products")->json('data.0'),
        ] as $read) {
            $row = $read();

            $this->assertSame([$a, $b], $row['gallery']);
            $this->assertSame(ProductGallery::version([$a, $b]), $row['gallery_version']);
            $this->assertCount(2, $row['gallery_items']);
            $this->assertSame(['ref', 'url', 'thumb_url', 'position'], array_keys($row['gallery_items'][0]));
            $this->assertSame($this->ref($a), $row['gallery_items'][0]['ref']);
            $this->assertSame($a, $row['gallery_items'][0]['url']);
            $this->assertSame(0, $row['gallery_items'][0]['position']);
            $this->assertSame(1, $row['gallery_items'][1]['position']);
            $this->assertNotSame('', $row['gallery_items'][0]['thumb_url']);
        }
    }

    public function test_customer_resources_do_not_carry_the_new_fields(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $product = $this->productWith($place, [$this->stored()]);
        $customer = $this->makeCustomer();
        Sanctum::actingAs($customer);

        foreach (["/api/v1/products/{$product->id}", "/api/v1/restaurants/{$place->id}/products"] as $url) {
            $body = json_encode($this->withHeaders(['X-App' => 'customer'])->getJson($url)->assertOk()->json());
            $this->assertStringNotContainsString('gallery_items', $body, $url);
            $this->assertStringNotContainsString('gallery_version', $body, $url);
        }
    }

    public function test_append_keeps_the_order_sent_refs_then_files_dedupes_and_returns_the_product(): void
    {
        $place = $this->makeRestaurant('مطعم');
        [$a, $b] = [$this->stored(), $this->stored()];
        $product = $this->productWith($place, [$a]);
        $other = $this->productWith($place, [$b]);
        $asset = $this->libraryAsset();

        $response = $this->place($place)->post($this->base($place, $product), [
            'gallery_media' => [$this->ref($b), 'asset:'.$asset->id, $this->ref($a), $this->ref($b)],
            'gallery' => [UploadedFile::fake()->image('n.jpg', 60, 60)],
            'expected_version' => ProductGallery::version([$a]),
        ], ['Accept' => 'application/json'])->assertOk();

        $gallery = $this->gallery($product);
        $this->assertCount(4, $gallery);
        $this->assertSame([$a, $b, $asset->url], array_slice($gallery, 0, 3), 'existing first, then refs in the order sent, deduped, then files');
        $this->assertSame($gallery, $response->json('data.gallery'));
        $this->assertSame(ProductGallery::version($gallery), $response->json('data.gallery_version'));
        $this->assertSame([0, 1, 2, 3], array_column($response->json('data.gallery_items'), 'position'));
        $this->assertSame([$b], $this->gallery($other), 'the other product is untouched');
    }

    public function test_append_needs_something_to_append_and_only_pictures(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $product = $this->productWith($place, []);

        $this->place($place)->postJson($this->base($place, $product), [])->assertStatus(422);
        $this->place($place)->post($this->base($place, $product), [
            'gallery' => [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertStatus(422);
        $this->place($place)->postJson($this->base($place, $product), ['gallery_media' => ['garbage']])
            ->assertStatus(422)->assertJsonPath('error', 'media_not_allowed');
    }

    public function test_append_over_the_cap_is_gallery_full_and_writes_nothing(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $urls = [];

        for ($i = 0; $i < Product::GALLERY_MAX - 1; $i++) {
            $urls[] = $this->stored();
        }

        $product = $this->productWith($place, $urls);
        $before = Storage::disk('public')->allFiles();

        $response = $this->place($place)->post($this->base($place, $product), [
            'gallery' => [UploadedFile::fake()->image('a.jpg', 50, 50), UploadedFile::fake()->image('b.jpg', 50, 50)],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame('gallery_full', $response->json('error'));
        $this->assertSame(Product::GALLERY_MAX, $response->json('meta.max'));
        $this->assertSame($urls, $this->gallery($product));
        $this->assertEqualsCanonicalizing($before, Storage::disk('public')->allFiles(), 'no file was stored');

        // One more fits: exactly the cap.
        $this->place($place)->post($this->base($place, $product), [
            'gallery' => [UploadedFile::fake()->image('a.jpg', 50, 50)],
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertCount(Product::GALLERY_MAX, $this->gallery($product));

        // A ref that is already in the gallery adds nothing, so it is not «full» either.
        $this->place($place)->postJson($this->base($place, $product), ['gallery_media' => [$this->ref($urls[0])]])->assertOk();
        $this->assertCount(Product::GALLERY_MAX, $this->gallery($product));
    }

    public function test_delete_removes_one_picture_and_its_file(): void
    {
        $place = $this->makeRestaurant('مطعم');
        [$a, $b, $c] = [$this->stored(), $this->stored(), $this->stored()];
        $product = $this->productWith($place, [$a, $b, $c]);

        $response = $this->place($place)->deleteJson($this->base($place, $product).'/'.$this->ref($b))->assertOk();

        $this->assertSame([$a, $c], $this->gallery($product));
        $this->assertFalse($this->exists($b));
        $this->assertTrue($this->exists($a));
        $this->assertSame([$this->ref($a), $this->ref($c)], array_column($response->json('data.gallery_items'), 'ref'));
        $this->assertSame([0, 1], array_column($response->json('data.gallery_items'), 'position'));
    }

    public function test_delete_keeps_the_file_when_it_is_protected_or_shared(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $asset = $this->libraryAsset();
        $shared = $this->stored();
        $forImage = $this->stored();
        $product = $this->productWith($place, [$asset->url, $shared, $forImage]);
        $this->productWith($place, [$shared]);
        $product->update(['image' => $forImage]);

        foreach ([$asset->url, $shared, $forImage] as $url) {
            $this->place($place)->deleteJson($this->base($place, $product).'/'.$this->ref($url))->assertOk();
        }

        $this->assertSame([], $this->gallery($product));
        $this->assertTrue(Storage::disk('public')->exists($asset->path), 'a library file is never deleted by whoever stopped using it');
        $this->assertTrue($this->exists($shared), 'another product still shows it');
        $this->assertTrue($this->exists($forImage), 'the product image still shows it');
    }

    public function test_delete_of_an_unknown_ref_is_404_and_only_this_products_entries_count(): void
    {
        $place = $this->makeRestaurant('مطعم');
        [$a, $b] = [$this->stored(), $this->stored()];
        $product = $this->productWith($place, [$a]);
        $sibling = $this->productWith($place, [$b]); // the same place, so `OwnImages` knows `$b`

        $response = $this->place($place)->deleteJson($this->base($place, $product).'/'.$this->ref($b))->assertNotFound();
        $this->assertSame('gallery_item_not_found', $response->json('error'));

        $this->place($place)->deleteJson($this->base($place, $product).'/not-a-ref')->assertNotFound()
            ->assertJsonPath('error', 'gallery_item_not_found');

        $this->assertSame([$b], $this->gallery($sibling));
        $this->assertTrue($this->exists($b));
    }

    public function test_reorder_needs_an_exact_permutation_and_never_deletes(): void
    {
        $place = $this->makeRestaurant('مطعم');
        [$a, $b, $c] = [$this->stored(), $this->stored(), $this->stored()];
        $product = $this->productWith($place, [$a, $b, $c]);
        $url = $this->base($place, $product).'/order';

        $response = $this->place($place)->putJson($url, ['order' => [$this->ref($c), $this->ref($a), $this->ref($b)]])->assertOk();
        $this->assertSame([$c, $a, $b], $this->gallery($product));
        $this->assertSame([$c, $a, $b], $response->json('data.gallery'));
        $this->assertSame([$this->ref($c), $this->ref($a), $this->ref($b)], array_column($response->json('data.gallery_items'), 'ref'));

        $bad = [
            'a missing entry' => [$this->ref($a), $this->ref($b)],
            'a duplicate' => [$this->ref($a), $this->ref($a), $this->ref($b)],
            'a stranger' => [$this->ref($a), $this->ref($b), $this->ref($this->stored())],
            'an extra one' => [$this->ref($a), $this->ref($b), $this->ref($c), $this->ref($this->stored())],
            'empty' => [],
        ];

        foreach ($bad as $why => $order) {
            $this->place($place)->putJson($url, ['order' => $order])->assertStatus(422);
        }

        $this->assertSame([$c, $a, $b], $this->gallery($product), 'a refused reorder changes nothing');
        $this->assertTrue($this->exists($a) && $this->exists($b) && $this->exists($c));
    }

    public function test_two_stale_clients_the_second_gets_409_with_the_current_gallery(): void
    {
        $place = $this->makeRestaurant('مطعم');
        [$a, $b] = [$this->stored(), $this->stored()];
        $product = $this->productWith($place, [$a, $b]);
        $token = ProductGallery::version([$a, $b]);

        // Client one reorders with the token it read.
        $this->place($place)->putJson($this->base($place, $product).'/order', [
            'order' => [$this->ref($b), $this->ref($a)], 'expected_version' => $token,
        ])->assertOk();

        // Client two still holds the old token — for each of the three mutations.
        $delete = $this->place($place)->deleteJson($this->base($place, $product).'/'.$this->ref($a).'?expected_version='.$token)->assertStatus(409);
        $this->assertSame('gallery_changed', $delete->json('error'));
        $this->assertSame([$this->ref($b), $this->ref($a)], array_column($delete->json('meta.gallery_items'), 'ref'));
        $this->assertSame(ProductGallery::version([$b, $a]), $delete->json('meta.gallery_version'));

        $this->place($place)->putJson($this->base($place, $product).'/order', [
            'order' => [$this->ref($a), $this->ref($b)], 'expected_version' => $token,
        ])->assertStatus(409)->assertJsonPath('error', 'gallery_changed');

        $before = Storage::disk('public')->allFiles();
        $append = $this->place($place)->post($this->base($place, $product), [
            'gallery' => [UploadedFile::fake()->image('n.jpg', 50, 50)], 'expected_version' => $token,
        ], ['Accept' => 'application/json'])->assertStatus(409);
        $this->assertSame('gallery_changed', $append->json('error'));

        $this->assertSame([$b, $a], $this->gallery($product), 'nothing the stale client sent was applied');
        $this->assertEqualsCanonicalizing($before, Storage::disk('public')->allFiles(), 'and its upload was never stored');

        // With the fresh token it goes through; with none at all it is last-write-wins.
        $this->place($place)->deleteJson($this->base($place, $product).'/'.$this->ref($a).'?expected_version='.ProductGallery::version([$b, $a]))->assertOk();
        $this->place($place)->deleteJson($this->base($place, $product).'/'.$this->ref($b))->assertOk();
        $this->assertSame([], $this->gallery($product));
    }

    public function test_a_foreign_place_cannot_touch_a_products_gallery(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $rival = $this->makeRestaurant('منافس');
        $a = $this->stored();
        $product = $this->productWith($place, [$a]);

        $this->place($rival)->postJson($this->base($place, $product), ['gallery_media' => [$this->ref($a)]])->assertNotFound();
        $this->place($rival)->deleteJson($this->base($place, $product).'/'.$this->ref($a))->assertNotFound();
        $this->place($rival)->putJson($this->base($place, $product).'/order', ['order' => [$this->ref($a)]])->assertNotFound();

        $this->assertSame([$a], $this->gallery($product));
    }

    public function test_the_gallery_endpoints_need_the_right_kind_of_account(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $a = $this->stored();
        $product = $this->productWith($place, [$a]);

        $this->postJson($this->base($place, $product), [])->assertUnauthorized();

        Sanctum::actingAs($this->makeCustomer());
        $this->withHeaders(['X-App' => 'restaurant'])->deleteJson($this->base($place, $product).'/'.$this->ref($a))->assertForbidden();
    }

    public function test_the_admin_mirror_appends_removes_and_reorders(): void
    {
        $place = $this->makeRestaurant('مطعم');
        [$a, $b] = [$this->stored(), $this->stored()];
        $product = $this->productWith($place, [$a]);
        $this->productWith($place, [$b]); // a picture of the same place, so `own:` can name it
        $base = $this->adminBase($place, $product);

        $this->admin()->postJson($base, ['gallery_media' => [$this->ref($b)]])->assertOk()->assertJsonPath('data.gallery', [$a, $b]);
        $this->admin()->putJson($base.'/order', ['order' => [$this->ref($b), $this->ref($a)]])->assertOk()->assertJsonPath('data.gallery', [$b, $a]);
        $this->admin()->deleteJson($base.'/'.$this->ref($b))->assertOk()->assertJsonPath('data.gallery', [$a]);

        $this->assertTrue($this->exists($b), 'the sibling product still shows it');
        $stale = ProductGallery::version([$a, $b]);
        $this->admin()->deleteJson($base.'/'.$this->ref($a).'?expected_version='.$stale)->assertStatus(409)->assertJsonPath('error', 'gallery_changed');
    }

    public function test_the_admin_mirror_honours_permission_scope_and_the_path(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $other = $this->makeRestaurant('آخر');
        $a = $this->stored();
        $product = $this->productWith($place, [$a]);
        $foreignProduct = $this->productWith($other, [$this->stored()]);
        $base = $this->adminBase($place, $product);

        // No products.update → 403 (a viewer only reads).
        $this->admin('viewer')->deleteJson($base.'/'.$this->ref($a))->assertForbidden();

        // A regional admin whose scope does not cover the place → 404, never 403.
        $elsewhere = City::query()->create([
            'name_ar' => 'مدينة أخرى', 'name_en' => 'Elsewhere', 'center_lat' => 30.0, 'center_lng' => 30.0,
            'pricing_type' => 'fixed_zone', 'default_min_order' => 0, 'is_active' => true,
        ]);
        $scoped = User::factory()->create(['type' => 'admin']);
        $scoped->assignRole('super_admin');
        $scoped->adminScopes()->create(['scope_type' => AdminScopeType::City, 'city_id' => $elsewhere->id]);
        Sanctum::actingAs($scoped);
        $this->withHeaders(['X-App' => 'admin'])->postJson($base, ['gallery_media' => [$this->ref($a)]])->assertNotFound();
        $this->withHeaders(['X-App' => 'admin'])->deleteJson($base.'/'.$this->ref($a))->assertNotFound();
        $this->withHeaders(['X-App' => 'admin'])->putJson($base.'/order', ['order' => [$this->ref($a)]])->assertNotFound();

        // A product that is not this restaurant's is a 404 in the path.
        $this->admin()->deleteJson($this->adminBase($place, $foreignProduct).'/x')->assertNotFound();

        // A place is not an admin.
        $this->place($place)->deleteJson($base.'/'.$this->ref($a))->assertForbidden();

        $this->assertSame([$a], $this->gallery($product));
    }
}
