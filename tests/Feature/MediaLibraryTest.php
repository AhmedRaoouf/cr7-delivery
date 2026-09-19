<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\MediaAsset;
use App\Models\MediaAudience;
use App\Models\MenuCategory;
use App\Models\PlaceGroup;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\StoreCategory;
use App\Models\User;
use App\Support\Media\MediaLibrary;
use App\Support\Media\MediaLibrarySync;
use App\Support\Media\ShippedAssets;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D-160 / doc 71 — the reusable media library.
 *
 * The rule under every test here: the library and the fields that use it are joined only
 * by a URL, so nothing that can be deleted may reach a library row, and what a place is
 * offered is decided once, on the server.
 */
class MediaLibraryTest extends TestCase
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

    private function admin(): static
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        return $this->withHeaders(['X-App' => 'admin']);
    }

    private function place(Restaurant $restaurant): static
    {
        Sanctum::actingAs($restaurant->owner);

        return $this->withHeaders(['X-App' => 'restaurant']);
    }

    /** @param  array<string, mixed>  $attrs */
    private function asset(array $attrs = []): MediaAsset
    {
        $n = ++$this->n;
        $path = "media-library/test-{$n}.jpg";
        Storage::disk('public')->put($path, "bytes-{$n}");

        $asset = MediaAsset::create([
            'name_ar' => $attrs['name_ar'] ?? "صورة {$n}",
            'name_en' => $attrs['name_en'] ?? "image {$n}",
            'filename' => "test-{$n}.jpg",
            'disk' => 'public',
            'path' => $path,
            'mime' => 'image/jpeg',
            'bytes' => 10,
            'sha1' => sha1("bytes-{$n}"),
            'source' => MediaAsset::SOURCE_UPLOADED,
            'usage_types' => $attrs['usage_types'] ?? ['product'],
            'visibility' => $attrs['visibility'] ?? 'global',
        ]);

        foreach ($attrs['audiences'] ?? [] as [$type, $id]) {
            MediaAudience::create(['media_asset_id' => $asset->id, 'audience_type' => $type, 'audience_id' => $id]);
        }

        if (isset($attrs['tags'])) {
            $asset->tags()->sync(ShippedAssets::tagIds($attrs['tags']));
        }

        return $asset;
    }

    /** @return list<string> the refs a place's picker offers */
    private function offered(Restaurant $restaurant, string $field): array
    {
        return collect($this->place($restaurant)->getJson("/api/v1/restaurant/media/picker?field={$field}&per_page=60")
            ->assertOk()->json('data'))->pluck('ref')->all();
    }

    // ── survival ────────────────────────────────────────────────────────────────

    public function test_library_assets_survive_deleting_products_categories_places_and_users(): void
    {
        $place = $this->makeRestaurant('مطعم الاختبار');
        $product = $this->makeProduct($place, 'برجر', 50);
        $asset = $this->asset();
        $product->update(['image' => $asset->url]);
        $category = MenuCategory::query()->where('restaurant_id', $place->id)->first();
        $category->update(['image' => $asset->url]);

        MediaAudience::create(['media_asset_id' => $asset->id, 'audience_type' => 'place', 'audience_id' => $place->id]);
        $group = PlaceGroup::create(['name_ar' => 'مجموعة']);
        $group->restaurants()->attach($place->id);
        $asset->update(['created_by' => $place->owner->id]);

        $product->forceDelete();
        $category->forceDelete();
        $place->forceDelete();
        $place->owner->forceDelete();

        $this->assertNotNull(MediaAsset::find($asset->id));
        $this->assertTrue(Storage::disk('public')->exists($asset->path));
        $this->assertNull(MediaAsset::find($asset->id)->created_by);
    }

    public function test_a_reset_of_demo_data_leaves_the_library_alone(): void
    {
        $asset = $this->asset();

        Artisan::call('demo:flush', ['--force' => true]);

        $this->assertNotNull(MediaAsset::find($asset->id));
        $this->assertNotNull(MediaAsset::query()->where('system_key', MediaLibrary::PLACEHOLDER_KEY)->first());
    }

    public function test_replacing_a_product_picture_never_deletes_a_library_file_or_one_another_field_still_shows(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $product = $this->makeProduct($place, 'برجر', 50);
        $other = $this->makeProduct($place, 'بيتزا', 60);
        $library = $this->asset();
        $shared = $this->asset(['visibility' => 'hidden']);

        $product->update(['image' => $library->url]);
        $product->update(['image' => $shared->url]);
        $other->update(['image' => $shared->url]);

        $this->admin()->patchJson("/api/v1/admin/restaurants/{$place->id}/products/{$product->id}", [
            'image' => UploadedFile::fake()->image('new.jpg'),
        ])->assertOk();

        $this->assertTrue(Storage::disk('public')->exists($library->path), 'library file must survive');
        $this->assertTrue(Storage::disk('public')->exists($shared->path));
    }

    public function test_a_file_two_products_share_survives_the_first_replacing_it_and_goes_with_the_last(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $a = $this->makeProduct($place, 'برجر', 50);
        $b = $this->makeProduct($place, 'بيتزا', 60);

        Storage::disk('public')->put('products/shared.jpg', 'x');
        $url = Storage::disk('public')->url('products/shared.jpg');
        $a->update(['image' => $url]);
        $b->update(['image' => $url]);

        $replace = fn ($product) => $this->admin()->patchJson("/api/v1/admin/restaurants/{$place->id}/products/{$product->id}", [
            'image' => UploadedFile::fake()->image('new.jpg'),
        ])->assertOk();

        $replace($a);
        $this->assertTrue(Storage::disk('public')->exists('products/shared.jpg'), 'another product still shows it');

        $replace($b);
        $this->assertFalse(Storage::disk('public')->exists('products/shared.jpg'), 'the last holder takes it with it, as before');
    }

    // ── the picker ──────────────────────────────────────────────────────────────

    public function test_a_places_own_current_images_are_offered_without_being_in_the_library(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $product = $this->makeProduct($place, 'برجر', 50);
        $product->update(['image' => 'https://cdn.test/own-dish.jpg', 'gallery' => ['https://cdn.test/own-gallery.jpg']]);
        $place->update(['logo' => 'https://cdn.test/logo.png', 'cover' => 'https://cdn.test/cover.jpg']);
        $rival = $this->makeRestaurant('منافس');
        $this->makeProduct($rival, 'شاورما', 40)->update(['image' => 'https://cdn.test/rival.jpg']);

        $items = collect($this->place($place)->getJson('/api/v1/restaurant/media/picker?field=product_image')
            ->assertOk()->json('data'));

        $own = $items->where('source', 'own')->pluck('url')->all();
        $this->assertEqualsCanonicalizing(['https://cdn.test/own-dish.jpg', 'https://cdn.test/own-gallery.jpg'], $own);
        $this->assertNotContains('https://cdn.test/rival.jpg', $items->pluck('url')->all());

        $logos = collect($this->place($place)->getJson('/api/v1/restaurant/media/picker?field=restaurant_logo')->json('data'));
        $this->assertSame(['https://cdn.test/logo.png'], $logos->where('source', 'own')->pluck('url')->values()->all());

        $covers = collect($this->place($place)->getJson('/api/v1/restaurant/media/picker?field=restaurant_cover')->json('data'));
        $this->assertSame(['https://cdn.test/cover.jpg'], $covers->where('source', 'own')->pluck('url')->values()->all());
    }

    public function test_global_assets_are_visible_to_every_place_and_hidden_ones_to_none(): void
    {
        $a = $this->makeRestaurant('أ');
        $b = $this->makeRestaurant('ب');
        $global = $this->asset();
        $hidden = $this->asset(['visibility' => 'hidden']);

        foreach ([$a, $b] as $place) {
            $refs = $this->offered($place, 'product_image');
            $this->assertContains("asset:{$global->id}", $refs);
            $this->assertNotContains("asset:{$hidden->id}", $refs);
        }
    }

    public function test_a_selected_place_sees_its_assets_and_others_do_not(): void
    {
        $a = $this->makeRestaurant('أ');
        $b = $this->makeRestaurant('ب');
        $asset = $this->asset(['visibility' => 'restricted', 'audiences' => [['place', $a->id]]]);

        $this->assertContains("asset:{$asset->id}", $this->offered($a, 'product_image'));
        $this->assertNotContains("asset:{$asset->id}", $this->offered($b, 'product_image'));
    }

    public function test_a_group_and_a_store_category_can_be_the_audience(): void
    {
        $member = $this->makeRestaurant('عضو');
        $outsider = $this->makeRestaurant('غريب');
        $pharmacy = StoreCategory::query()->create(['slug' => 'pharmacies', 'name_ar' => 'صيدليات', 'name_en' => 'Pharmacies']);
        $inCategory = $this->makeRestaurant('صيدلية');
        $inCategory->update(['store_category_id' => $pharmacy->id]);

        $group = PlaceGroup::create(['name_ar' => 'الشاطئ']);
        $group->restaurants()->attach($member->id);

        $byGroup = $this->asset(['visibility' => 'restricted', 'audiences' => [['group', $group->id]]]);
        $byCategory = $this->asset(['visibility' => 'restricted', 'audiences' => [['store_category', $pharmacy->id]]]);

        $this->assertContains("asset:{$byGroup->id}", $this->offered($member, 'product_image'));
        $this->assertNotContains("asset:{$byGroup->id}", $this->offered($outsider, 'product_image'));
        $this->assertNotContains("asset:{$byGroup->id}", $this->offered($inCategory, 'product_image'));

        $this->assertContains("asset:{$byCategory->id}", $this->offered($inCategory, 'product_image'));
        $this->assertNotContains("asset:{$byCategory->id}", $this->offered($member, 'product_image'));

        // Leaving the group takes the picture away again — it is derived, not copied.
        $group->restaurants()->detach($member->id);
        $this->assertNotContains("asset:{$byGroup->id}", $this->offered($member, 'product_image'));
    }

    public function test_a_field_only_offers_assets_valid_for_its_usage(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $product = $this->asset(['usage_types' => ['product']]);
        $cover = $this->asset(['usage_types' => ['cover']]);
        $both = $this->asset(['usage_types' => ['product', 'category']]);

        $forProduct = $this->offered($place, 'product_image');
        $this->assertContains("asset:{$product->id}", $forProduct);
        $this->assertContains("asset:{$both->id}", $forProduct);
        $this->assertNotContains("asset:{$cover->id}", $forProduct);

        $forCover = $this->offered($place, 'restaurant_cover');
        $this->assertSame(["asset:{$cover->id}"], array_values(array_filter($forCover, fn ($r) => str_starts_with($r, 'asset:') && $r !== 'asset:'.MediaAsset::query()->where('system_key', 'default_placeholder')->value('id'))));
        $this->assertNotContains("asset:{$product->id}", $forCover);

        $this->place($place)->getJson('/api/v1/restaurant/media/picker?field=nonsense')->assertStatus(422);
    }

    public function test_picking_writes_the_url_and_a_forbidden_pick_is_refused(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $category = MenuCategory::query()->create(['restaurant_id' => $place->id, 'name_ar' => 'قسم', 'name_en' => 'Cat', 'sort_order' => 0, 'is_active' => true]);
        $allowed = $this->asset(['usage_types' => ['product']]);
        $hidden = $this->asset(['visibility' => 'hidden']);
        $wrongUsage = $this->asset(['usage_types' => ['cover']]);

        $create = fn (string $ref) => $this->place($place)->postJson('/api/v1/restaurant/products', [
            'menu_category_id' => $category->id, 'name_ar' => 'طبق', 'name_en' => 'Dish', 'price' => 30,
            'image_media' => $ref,
        ]);

        $create("asset:{$allowed->id}")->assertCreated();
        $this->assertSame($allowed->url, Product::query()->where('name_ar', 'طبق')->value('image'));

        foreach (["asset:{$hidden->id}", "asset:{$wrongUsage->id}", 'asset:99999', 'own:'.str_repeat('a', 40), 'garbage'] as $ref) {
            $create($ref)->assertStatus(422)->assertJsonPath('error', 'media_not_allowed');
        }
    }

    public function test_a_place_can_reuse_one_of_its_own_pictures_by_ref(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $first = $this->makeProduct($place, 'برجر', 50);
        $second = $this->makeProduct($place, 'بيتزا', 60);
        $first->update(['image' => 'https://cdn.test/own.jpg']);

        $ref = collect($this->place($place)->getJson('/api/v1/restaurant/media/picker?field=product_image')->json('data'))
            ->firstWhere('url', 'https://cdn.test/own.jpg')['ref'];

        $this->place($place)->patchJson("/api/v1/restaurant/products/{$second->id}", ['image_media' => $ref])->assertOk();

        $this->assertSame('https://cdn.test/own.jpg', $second->fresh()->image);
    }

    public function test_a_place_cannot_pick_another_places_own_picture(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $rival = $this->makeRestaurant('منافس');
        $this->makeProduct($rival, 'شاورما', 40)->update(['image' => 'https://cdn.test/rival.jpg']);
        $mine = $this->makeProduct($place, 'برجر', 50);

        $this->place($place)->patchJson("/api/v1/restaurant/products/{$mine->id}", ['image_media' => 'own:'.sha1('https://cdn.test/rival.jpg')])
            ->assertStatus(422);
    }

    public function test_an_admin_picker_sees_hidden_assets_and_the_named_places_own_pictures(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $this->makeProduct($place, 'برجر', 50)->update(['image' => 'https://cdn.test/own.jpg']);
        $hidden = $this->asset(['visibility' => 'hidden']);

        $items = collect($this->admin()->getJson("/api/v1/admin/media/picker?field=product_image&restaurant_id={$place->id}")
            ->assertOk()->json('data'));

        $this->assertContains("asset:{$hidden->id}", $items->pluck('ref')->all());
        $this->assertContains('https://cdn.test/own.jpg', $items->where('source', 'own')->pluck('url')->all());
    }

    // ── admin management ────────────────────────────────────────────────────────

    public function test_an_admin_uploads_edits_and_removes_an_asset_without_touching_its_uses(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $product = $this->makeProduct($place, 'برجر', 50);

        $id = $this->admin()->postJson('/api/v1/admin/media', [
            'file' => UploadedFile::fake()->image('burger.jpg', 400, 300),
            'name_ar' => 'برجر لحم',
            'usage_types' => ['product'],
            'tags' => ['food', 'product'],
        ])->assertCreated()->json('data.id');

        $asset = MediaAsset::findOrFail($id);
        $this->assertStringStartsWith('media-library/', $asset->path);
        $this->assertSame(['food', 'product'], $asset->tags()->orderBy('slug')->pluck('slug')->all());

        $this->admin()->patchJson("/api/v1/admin/media/{$id}", [
            'visibility' => 'restricted',
            'audiences' => [['type' => 'place', 'id' => $place->id]],
            'name_ar' => 'برجر جديد',
        ])->assertOk()->assertJsonPath('data.audiences.0.name', 'مطعم');

        $product->update(['image' => $asset->url]);

        $this->admin()->deleteJson("/api/v1/admin/media/{$id}")->assertOk();
        $this->assertSoftDeleted($asset);
        $this->assertTrue(Storage::disk('public')->exists($asset->path));
        $this->assertSame($asset->url, $product->fresh()->image);
        $this->assertNotContains("asset:{$id}", $this->offered($place, 'product_image'));

        $this->admin()->postJson("/api/v1/admin/media/{$id}/restore")->assertOk();
        $this->assertNull($asset->fresh()->deleted_at);
    }

    public function test_purge_is_refused_while_the_picture_is_in_use_and_allowed_once_it_is_not(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $product = $this->makeProduct($place, 'برجر', 50);
        $asset = $this->asset();
        $product->update(['image' => $asset->url]);

        $this->admin()->deleteJson("/api/v1/admin/media/{$asset->id}?purge=1")
            ->assertStatus(409)->assertJsonPath('error', 'media_in_use');
        $this->assertTrue(Storage::disk('public')->exists($asset->path));

        $product->update(['image' => null]);

        $this->admin()->deleteJson("/api/v1/admin/media/{$asset->id}?purge=1")->assertOk();
        $this->assertNull(MediaAsset::withTrashed()->find($asset->id));
        $this->assertFalse(Storage::disk('public')->exists($asset->path));
    }

    public function test_the_default_placeholder_is_protected(): void
    {
        $placeholder = MediaAsset::query()->where('system_key', 'default_placeholder')->firstOrFail();

        $this->admin()->deleteJson("/api/v1/admin/media/{$placeholder->id}")->assertStatus(409)->assertJsonPath('error', 'media_protected');
        $this->admin()->deleteJson("/api/v1/admin/media/{$placeholder->id}?purge=1")->assertStatus(409);

        // A form that drops `fallback` from the usage list cannot take the role away.
        $this->admin()->patchJson("/api/v1/admin/media/{$placeholder->id}", ['usage_types' => ['product']])->assertOk();
        $this->assertContains('fallback', $placeholder->fresh()->usage_types);
    }

    public function test_a_duplicate_upload_is_refused_with_the_existing_id(): void
    {
        $file = fn () => UploadedFile::fake()->image('same.jpg', 100, 100);
        $first = $this->admin()->postJson('/api/v1/admin/media', ['file' => $file(), 'usage_types' => ['product']])->assertCreated()->json('data.id');

        $this->admin()->postJson('/api/v1/admin/media', ['file' => $file(), 'usage_types' => ['product']])
            ->assertStatus(409)->assertJsonPath('error', 'media_duplicate')->assertJsonPath('meta.existing_id', $first);
    }

    public function test_a_place_cannot_reach_the_library_and_a_scoped_admin_cannot_write_it(): void
    {
        $place = $this->makeRestaurant('مطعم');
        $this->place($place)->getJson('/api/v1/admin/media')->assertForbidden();

        $scoped = User::factory()->create(['type' => 'admin']);
        $scoped->assignRole('super_admin');
        $scoped->adminScopes()->create(['scope_type' => AdminScopeType::City, 'city_id' => $this->city->id]);
        Sanctum::actingAs($scoped);

        $this->withHeaders(['X-App' => 'admin'])->postJson('/api/v1/admin/media', ['file' => UploadedFile::fake()->image('x.jpg')])
            ->assertNotFound();
    }

    // ── search and filters ──────────────────────────────────────────────────────

    public function test_the_library_searches_and_filters(): void
    {
        $place = $this->makeRestaurant('مطعم الشاطئ');
        $group = PlaceGroup::create(['name_ar' => 'مجموعة الصيف']);

        $burger = $this->asset(['name_ar' => 'برجر لحم', 'name_en' => 'beef burger', 'usage_types' => ['product'], 'tags' => ['food']]);
        $cover = $this->asset(['name_ar' => 'غلاف البحر', 'usage_types' => ['cover'], 'visibility' => 'restricted', 'audiences' => [['place', $place->id]]]);
        $grouped = $this->asset(['name_ar' => 'شعار', 'usage_types' => ['logo'], 'visibility' => 'restricted', 'audiences' => [['group', $group->id]]]);
        $hidden = $this->asset(['name_ar' => 'مخفي', 'visibility' => 'hidden']);
        $burger->update(['source' => 'shipped', 'source_key' => 'catalog/dishes/x.jpg']);

        $placeholderId = MediaAsset::query()->where('system_key', 'default_placeholder')->value('id');
        $raw = fn (string $qs) => collect($this->admin()->getJson("/api/v1/admin/media?per_page=100&{$qs}")->assertOk()->json('data'))->pluck('id')->all();
        // The protected placeholder is in every library; the assertions are about the rest.
        $ids = fn (string $qs) => array_values(array_diff($raw($qs), [$placeholderId]));

        $this->assertSame([$burger->id], $ids('q='.urlencode('برجر')));
        $this->assertSame([$burger->id], $ids('q=burger'));
        $this->assertSame([$burger->id], $ids('q=food'), 'tag search');
        $this->assertSame([$burger->id], $ids('q=test-1.jpg'), 'filename search');
        $this->assertSame([$cover->id], $ids('q='.urlencode('الشاطئ')), 'assigned-place search');
        $this->assertSame([$grouped->id], $ids('q='.urlencode('الصيف')), 'assigned-group search');
        $this->assertContains($cover->id, $ids('q=cover'), 'usage word');

        $this->assertEqualsCanonicalizing([$cover->id], $ids('filter[usage]=cover'));
        $this->assertEqualsCanonicalizing([$cover->id], $ids("filter[place_id]={$place->id}"));
        $this->assertEqualsCanonicalizing([$grouped->id], $ids("filter[group_id]={$group->id}"));
        $this->assertEqualsCanonicalizing([$hidden->id], $ids('filter[visibility]=hidden'));
        $this->assertContains($burger->id, $ids('filter[visibility]=shared'));
        $this->assertNotContains($cover->id, $ids('filter[visibility]=shared'));
        $this->assertSame([$burger->id], $ids('filter[source]=shipped'));
        $this->assertContains($cover->id, $ids('filter[source]=uploaded'));
        $this->assertNotContains($burger->id, $ids('filter[source]=uploaded'));
        $this->assertSame([$placeholderId], $raw('filter[source]=system'));
        $this->assertSame([$burger->id], $ids('filter[tag]=food'));

        // unused: nothing shows a picture yet; use one and it drops out.
        $this->makeProduct($place, 'طبق', 10)->update(['image' => $burger->url]);
        $this->assertNotContains($burger->id, $ids('filter[unused]=1'));
        $this->assertContains($burger->id, $ids('filter[unused]=0'));
        $this->assertContains($cover->id, $ids('filter[unused]=1'));

        $this->assertSame([$burger->id, $cover->id, $grouped->id, $hidden->id],
            array_values(array_intersect($ids('date_from='.now()->subDay()->toDateString().'&sort=created_at'), [$burger->id, $cover->id, $grouped->id, $hidden->id])));
        $this->assertSame([], $ids('date_to='.now()->subDays(2)->toDateString()));

        // removed assets are hidden by default and reachable on request.
        $cover->delete();
        $this->assertNotContains($cover->id, $ids(''));
        $this->assertSame([$cover->id], $ids('filter[state]=removed'));
    }

    // ── available shipped assets ────────────────────────────────────────────────

    public function test_shipped_assets_are_listed_as_available_and_never_added_by_themselves(): void
    {
        $before = MediaAsset::query()->count();

        $available = collect($this->admin()->getJson('/api/v1/admin/media/available?per_page=100')->assertOk()->json('data'));
        $this->assertGreaterThan(0, $available->count());
        $this->assertSame($before, MediaAsset::query()->count(), 'listing must not add anything');
        $this->assertNotContains('media/system/default-placeholder.webp', $available->pluck('key')->all());

        // A picture in a shipped folder is available and in no picker until it is added.
        $place = $this->makeRestaurant('مطعم');
        $key = $available->firstWhere('folder', 'catalog/dishes')['key'];
        $this->assertSame([], array_filter($this->offered($place, 'product_image'), fn ($r) => str_contains($r, 'catalog')));

        $this->admin()->postJson('/api/v1/admin/media/available', ['keys' => [$key]])->assertCreated();

        $asset = MediaAsset::query()->where('source_key', $key)->firstOrFail();
        $this->assertSame(['product'], $asset->usage_types);
        $this->assertContains("asset:{$asset->id}", $this->offered($place, 'product_image'));

        $after = collect($this->admin()->getJson('/api/v1/admin/media/available?per_page=100')->json('data'));
        $this->assertNotContains($key, $after->pluck('key')->all(), 'once added it is no longer «available»');

        // Removing it returns it to «available» and adding again restores the same row.
        $this->admin()->deleteJson("/api/v1/admin/media/{$asset->id}")->assertOk();
        $again = collect($this->admin()->getJson('/api/v1/admin/media/available?per_page=100')->json('data'));
        $this->assertContains($key, $again->pluck('key')->all());
        $this->admin()->postJson('/api/v1/admin/media/available', ['keys' => [$key]])->assertCreated();
        $this->assertSame(1, MediaAsset::withTrashed()->where('source_key', $key)->count());
        $this->assertNull($asset->fresh()->deleted_at);

        $this->admin()->postJson('/api/v1/admin/media/available', ['keys' => ['catalog/nope.jpg']])->assertStatus(422);
    }

    public function test_a_file_dropped_into_a_shipped_folder_is_available_but_not_active(): void
    {
        $dir = public_path('shipped/dashboard');
        @mkdir($dir, 0775, true);
        $file = $dir.'/zz-test-new-asset.png';
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        try {
            $this->assertTrue(ShippedAssets::available()->has('shipped/dashboard/zz-test-new-asset.png'));
            $this->assertSame(0, MediaAsset::query()->where('source_key', 'shipped/dashboard/zz-test-new-asset.png')->count());
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    // ── placeholder ─────────────────────────────────────────────────────────────

    public function test_a_missing_product_or_cover_shows_the_placeholder_and_a_real_one_is_never_replaced(): void
    {
        $placeholder = MediaLibrary::placeholderUrl();
        $this->assertNotNull($placeholder);
        $this->assertStringEndsWith('media/system/default-placeholder.webp', $placeholder);
        $this->assertFileExists(public_path('media/system/default-placeholder.webp'));

        $place = $this->makeRestaurant('مطعم');
        $bare = $this->makeProduct($place, 'بلا صورة', 10);
        $real = $this->makeProduct($place, 'بصورة', 10);
        $real->update(['image' => 'https://cdn.test/real.jpg']);

        $list = collect($this->getJson("/api/v1/restaurants/{$place->id}/products")->assertOk()->json('data'));
        $this->assertSame($placeholder, $list->firstWhere('id', $bare->id)['image']);
        $this->assertSame('https://cdn.test/real.jpg', $list->firstWhere('id', $real->id)['image']);

        $this->assertSame($placeholder, $this->getJson("/api/v1/restaurants/{$place->id}")->assertOk()->json('data.cover'));
        $place->update(['cover' => 'https://cdn.test/cover.jpg']);
        $this->assertSame('https://cdn.test/cover.jpg', $this->getJson("/api/v1/restaurants/{$place->id}")->json('data.cover'));

        $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('data.media.placeholder_url', $placeholder);
    }

    // ── registry ────────────────────────────────────────────────────────────────

    public function test_the_system_media_rebuilds_idempotently_and_keeps_an_admins_edits(): void
    {
        $asset = MediaAsset::query()->where('system_key', 'default_placeholder')->firstOrFail();
        $asset->update(['name_ar' => 'اسم عدلته', 'visibility' => 'hidden', 'bytes' => 1]);

        $result = MediaLibrarySync::run();
        MediaLibrarySync::run();

        $this->assertSame([], $result['missing']);
        $this->assertSame(1, MediaAsset::withTrashed()->where('system_key', 'default_placeholder')->count());

        $fresh = $asset->fresh();
        $this->assertSame('اسم عدلته', $fresh->name_ar, 'sync must not undo an edit');
        $this->assertSame('hidden', $fresh->visibility->value);
        $this->assertGreaterThan(1, $fresh->bytes, 'file facts are refreshed from disk');
        $this->assertTrue($fresh->is_protected);
    }

    public function test_deleting_a_group_clears_its_rules_and_hides_the_asset_from_everyone(): void
    {
        $member = $this->makeRestaurant('عضو');
        $group = PlaceGroup::create(['name_ar' => 'مجموعة']);
        $group->restaurants()->attach($member->id);
        $asset = $this->asset(['visibility' => 'restricted', 'audiences' => [['group', $group->id]]]);
        $this->assertContains("asset:{$asset->id}", $this->offered($member, 'product_image'));

        $this->admin()->deleteJson("/api/v1/admin/place-groups/{$group->id}")->assertOk();

        $this->assertSame(0, MediaAudience::query()->where('media_asset_id', $asset->id)->count());
        $this->assertNotNull(MediaAsset::find($asset->id));
        $this->assertNotContains("asset:{$asset->id}", $this->offered($member, 'product_image'));
    }
}
