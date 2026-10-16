<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Banner;
use App\Models\City;
use App\Models\Faq;
use App\Models\MediaAsset;
use App\Models\MenuCategory;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\ServiceZone;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The web dashboards have one input per piece of copy, so the English side arrives blank and
 * used to answer 422. An empty English field now stores the Arabic text; an update that does
 * not mention it leaves the stored English alone; a request with neither still fails.
 */
class EnglishFallsBackToArabicTest extends TestCase
{
    use RefreshDatabase;

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

        return $this->withHeader('X-App', 'admin');
    }

    public function test_a_place_category_and_dish_save_without_english(): void
    {
        $place = $this->makeRestaurant('مطعم');
        Sanctum::actingAs($place->owner);
        $this->withHeader('X-App', 'restaurant');

        $this->postJson('/api/v1/restaurant/categories', ['name_ar' => 'مشويات'])
            ->assertCreated();
        $category = MenuCategory::query()->where('name_ar', 'مشويات')->firstOrFail();
        $this->assertSame('مشويات', $category->name_en);

        $this->postJson('/api/v1/restaurant/products', [
            'menu_category_id' => $category->id, 'name_ar' => 'كباب', 'name_en' => '', 'price' => 90,
        ])->assertCreated();
        $this->assertSame('كباب', Product::query()->where('name_ar', 'كباب')->value('name_en'));

        // An English name that was typed is kept; neither language still fails.
        $this->postJson('/api/v1/restaurant/categories', ['name_ar' => 'سلطات', 'name_en' => 'Salads'])->assertCreated();
        $this->assertSame('Salads', MenuCategory::query()->where('name_ar', 'سلطات')->value('name_en'));
        $this->postJson('/api/v1/restaurant/categories', [])->assertStatus(422)->assertJsonValidationErrors(['name_ar', 'name_en']);

        // An update that does not mention the English name leaves it alone.
        $dish = Product::query()->where('name_ar', 'كباب')->firstOrFail();
        $dish->update(['name_en' => 'Kebab']);
        $this->patchJson("/api/v1/restaurant/products/{$dish->id}", ['name_ar' => 'كباب مشكل'])->assertOk();
        $this->assertSame('Kebab', $dish->fresh()->name_en);
    }

    public function test_an_admin_creates_a_place_without_english_name_or_branch_phone(): void
    {
        $this->admin()->postJson('/api/v1/admin/restaurants', [
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'name_ar' => 'صيدلية النور',
            'owner_phone' => '01033445566',
            'latitude' => 31.4463,
            'longitude' => 31.5372,
        ])->assertCreated();

        $place = Restaurant::query()->where('name_ar', 'صيدلية النور')->firstOrFail();
        $this->assertSame('صيدلية النور', $place->name_en);
        $this->assertSame('01033445566', $place->phone);
    }

    public function test_a_faq_saves_without_english(): void
    {
        $this->admin()->postJson('/api/v1/admin/faqs', ['question_ar' => 'كيف أطلب؟', 'answer_ar' => 'من التطبيق.'])
            ->assertCreated();

        $faq = Faq::query()->firstOrFail();
        $this->assertSame('كيف أطلب؟', $faq->question_en);
        $this->assertSame('من التطبيق.', $faq->answer_en);
    }

    public function test_a_city_and_a_zone_save_without_english(): void
    {
        $admin = $this->admin();

        $admin->postJson('/api/v1/admin/cities', [
            'name_ar' => 'مدينة جديدة', 'center_lat' => 31.2, 'center_lng' => 31.3, 'pricing_type' => 'fixed_zone',
        ])->assertCreated();
        $city = City::query()->where('name_ar', 'مدينة جديدة')->firstOrFail();
        $this->assertSame('مدينة جديدة', $city->name_en);

        $admin->postJson('/api/v1/admin/zones', [
            'city_id' => $city->id, 'name_ar' => 'حي جديد', 'center_lat' => 31.2, 'center_lng' => 31.3,
        ])->assertCreated();
        $this->assertSame('حي جديد', ServiceZone::query()->where('name_ar', 'حي جديد')->value('name_en'));

        // The coordinates are NOT NULL columns the locator measures from: still required.
        $admin->postJson('/api/v1/admin/cities', ['name_ar' => 'بلا موقع', 'pricing_type' => 'fixed_zone'])
            ->assertStatus(422)->assertJsonValidationErrors(['center_lat', 'center_lng']);

        // An update that leaves the English out does not overwrite it.
        $admin->patchJson("/api/v1/admin/cities/{$city->id}", ['name_ar' => 'اسم آخر'])->assertOk();
        $this->assertSame('مدينة جديدة', $city->fresh()->name_en);
    }

    public function test_a_broadcast_falls_back_to_the_arabic_copy(): void
    {
        $customer = User::factory()->create(['type' => 'customer', 'is_active' => true]);

        $this->admin()->postJson('/api/v1/admin/notifications/broadcast', [
            'audience' => 'customers', 'title_ar' => 'عرض اليوم', 'body_ar' => 'خصم على كل الطلبات',
        ])->assertCreated();

        $payload = Notification::query()->where('user_id', $customer->id)->firstOrFail()->payload;
        $payload = is_array($payload) ? $payload : json_decode((string) $payload, true);
        $this->assertSame('عرض اليوم', $payload['title_en']);
        $this->assertSame('خصم على كل الطلبات', $payload['body_en']);
    }

    public function test_a_banner_is_created_from_an_upload_or_a_library_pick_and_not_from_nothing(): void
    {
        $admin = $this->admin();

        $admin->postJson('/api/v1/admin/banners', ['title_ar' => 'بدون صورة'])
            ->assertStatus(422)->assertJsonValidationErrors(['image_ar']);

        $asset = MediaAsset::create([
            'name_ar' => 'صورة', 'name_en' => 'image', 'filename' => 'b.jpg', 'disk' => 'public',
            'path' => 'media-library/b.jpg', 'mime' => 'image/jpeg', 'bytes' => 10, 'sha1' => sha1('b'),
            'source' => MediaAsset::SOURCE_UPLOADED, 'usage_types' => ['banner'], 'visibility' => 'global',
        ]);
        Storage::disk('public')->put('media-library/b.jpg', 'b');

        $admin->postJson('/api/v1/admin/banners', ['title_ar' => 'من المكتبة', 'image_ar_media' => "asset:{$asset->id}"])
            ->assertCreated();
        $this->assertSame($asset->url, Banner::query()->where('title_ar', 'من المكتبة')->value('image_ar'));

        $admin->post('/api/v1/admin/banners', ['title_ar' => 'مرفوعة', 'image_ar' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->assertNotNull(Banner::query()->where('title_ar', 'مرفوعة')->value('image_ar'));
    }
}
