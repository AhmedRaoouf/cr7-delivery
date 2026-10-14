<?php

namespace Tests\Feature\Marketing;

use App\Models\Banner;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * 2026-10-14 — «بيف بيكاتا من رملة» opened سندوتش عمر: the banner form asked for a place
 * number typed by hand, and `1` was stored as it came. The picker reads these lookups,
 * and the API refuses a destination that names nothing.
 */
class BannerDestinationTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    private User $admin;

    private Restaurant $omar;

    private Restaurant $ramla;

    private Product $smash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();

        $this->omar = $this->makeRestaurant('سندوتش عمر');
        $this->ramla = $this->makeRestaurant('رمله');
        $this->smash = $this->dish($this->ramla, 'ساندوتش سموكي سماش دبل', 185, 160);
        $this->dish($this->omar, 'طبق أرز مع سبيط مقلي', 120);

        $this->admin = $this->globalAdmin();
        $this->actingAsAdmin($this->admin);
    }

    public function test_places_carry_what_a_person_recognises(): void
    {
        $this->list('/api/v1/admin/lookups/places', ['filter' => ['search' => 'رمله']])
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.id', $this->ramla->id)
            ->assertJsonPath('data.0.products_count', 1)
            ->assertJsonStructure(['data' => [[
                'id', 'name', 'name_en', 'logo', 'logo_thumb', 'cover_thumb', 'category', 'city', 'zone',
                'rating_avg', 'rating_count', 'products_count', 'is_active', 'is_open',
            ]]]);

        // The picker resolves a stored value by id, so an edit opens on the chosen card.
        $this->assertSame([$this->omar->id], $this->idsOf('/api/v1/admin/lookups/places', ['filter' => ['id' => $this->omar->id]]));
    }

    public function test_products_are_found_by_their_name_or_their_place_and_name_the_place(): void
    {
        $this->list('/api/v1/admin/lookups/products', ['filter' => ['search' => 'سماش']])
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.id', $this->smash->id)
            ->assertJsonPath('data.0.price', 185)
            ->assertJsonPath('data.0.discount_price', 160)
            ->assertJsonPath('data.0.category', 'الأطباق')
            ->assertJsonPath('data.0.restaurant.id', $this->ramla->id)
            ->assertJsonPath('data.0.restaurant.name', 'رمله')
            ->assertJsonStructure(['data' => [['image', 'image_thumb', 'is_available', 'restaurant' => ['logo_thumb', 'is_active']]]]);

        $this->assertSame([$this->smash->id], $this->idsOf('/api/v1/admin/lookups/products', ['filter' => ['search' => 'رمله']]));
        $this->assertSame([$this->smash->id], $this->idsOf('/api/v1/admin/lookups/products', ['filter' => ['restaurant_id' => $this->ramla->id]]));
        $this->list('/api/v1/admin/lookups/products', ['filter' => ['is_active' => '1']])->assertJsonPath('meta.count', 2);
    }

    public function test_a_city_admin_sees_only_their_own_places_dishes(): void
    {
        $this->moveToOtherCity($this->ramla);
        $this->actingAsAdmin($this->cityAdmin($this->city));

        $this->assertSame([$this->omar->id], $this->idsOf('/api/v1/admin/lookups/places'));
        $this->list('/api/v1/admin/lookups/products')->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.restaurant.id', $this->omar->id);
    }

    public function test_promo_codes_are_listed_by_code(): void
    {
        PromoCode::query()->create([
            'code' => 'WELCOME20', 'title_ar' => 'أول طلب', 'title_en' => 'First order',
            'type' => 'percent', 'value' => 20, 'is_active' => true,
        ]);

        $this->list('/api/v1/admin/lookups/promo_codes', ['filter' => ['search' => 'welcome']])
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.code', 'WELCOME20')
            ->assertJsonPath('data.0.type', 'percent');
    }

    public function test_a_banner_pointing_at_nothing_is_refused(): void
    {
        $body = fn (string $type, string $value) => [
            'title_ar' => 'بيف بيكاتا', 'image_ar' => UploadedFile::fake()->image('b.png', 800, 400),
            'link_type' => $type, 'link_value' => $value,
        ];

        $this->post('/api/v1/admin/banners', $body('restaurant', '999999'), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('link_value');
        $this->post('/api/v1/admin/banners', $body('product', 'سماش'), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('link_value');
        $this->post('/api/v1/admin/banners', $body('promo', 'NOPE'), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('link_value');
        $this->post('/api/v1/admin/banners', $body('restaurant', ''), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('link_value');

        $this->post('/api/v1/admin/banners', $body('product', (string) $this->smash->id), ['Accept' => 'application/json'])
            ->assertCreated();
        $this->assertSame((string) $this->smash->id, Banner::query()->latest('id')->value('link_value'));
    }

    public function test_switching_an_old_slide_off_is_not_blocked_by_its_destination(): void
    {
        $banner = Banner::query()->create([
            'title_ar' => 'قديم', 'image_ar' => 'https://example.test/b.png',
            'link_type' => 'restaurant', 'link_value' => '999999', 'is_active' => true,
        ]);

        $this->post("/api/v1/admin/banners/{$banner->id}", ['_method' => 'PATCH', 'is_active' => '0'], ['Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_the_admin_list_names_where_each_slide_lands(): void
    {
        Banner::query()->create(['title_ar' => 'منتج', 'image_ar' => 'https://example.test/a.png', 'link_type' => 'product', 'link_value' => (string) $this->smash->id, 'sort_order' => 1]);
        Banner::query()->create(['title_ar' => 'مكان', 'image_ar' => 'https://example.test/b.png', 'link_type' => 'restaurant', 'link_value' => (string) $this->ramla->id, 'sort_order' => 2]);
        Banner::query()->create(['title_ar' => 'محذوف', 'image_ar' => 'https://example.test/c.png', 'link_type' => 'restaurant', 'link_value' => '999999', 'sort_order' => 3]);
        Banner::query()->create(['title_ar' => 'بلا', 'image_ar' => 'https://example.test/d.png', 'link_type' => 'none', 'sort_order' => 4]);

        $this->getJson('/api/v1/admin/banners')->assertOk()
            ->assertJsonPath('data.0.destination.label', 'ساندوتش سموكي سماش دبل')
            ->assertJsonPath('data.0.destination.sub', 'رمله')
            ->assertJsonPath('data.1.destination.label', 'رمله')
            ->assertJsonPath('data.2.destination.missing', true)
            ->assertJsonPath('data.3.destination', null);
    }

    private function dish(Restaurant $restaurant, string $name, float $price, ?float $discount = null): Product
    {
        $category = MenuCategory::query()->create(['restaurant_id' => $restaurant->id, 'name_ar' => 'الأطباق', 'name_en' => 'Plates']);

        return Product::query()->create([
            'restaurant_id' => $restaurant->id, 'menu_category_id' => $category->id,
            'name_ar' => $name, 'name_en' => $name, 'price' => $price, 'discount_price' => $discount,
            'is_available' => true,
        ]);
    }
}
