<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Cuisine;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\StoreCategory;
use App\Models\User;
use App\Support\Catalog\CuisineClassifier;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Doc 82 — the cuisine filter belongs to the dish.
 *
 * «عايزين الفلتر بتاع التصنيفات … يبقا مربوط بكل العناصر الموجوده عندنا في التطبيق …
 * واي عنصر مش له تصنيف … ضيفه علي حسب اسمه … لو ملوش خليه في other … متظهرش في
 * التطبيق … ويبقا من الاختيارات اختيار انه يعمل category جديد … اسمه وصورته required».
 */
class DishCuisineTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $place;

    /** @var array<string, Cuisine> */
    private array $c = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
        Storage::fake('public');

        foreach (['pizza' => 'بيتزا', 'burger' => 'برجر', 'sandwich' => 'سندوتشات', 'chicken' => 'دجاج', 'grill' => 'مشويات', 'egyptian' => 'مصري'] as $slug => $ar) {
            $this->c[$slug] = Cuisine::query()->create(['name_ar' => $ar, 'name_en' => ucfirst($slug), 'icon' => $slug, 'is_active' => true]);
        }

        $this->place = $this->makeRestaurant('رملة');
    }

    private function fallback(): Cuisine
    {
        return Cuisine::query()->where('is_fallback', true)->firstOrFail();
    }

    private function dish(string $name, string $section, ?Restaurant $place = null): Product
    {
        $place ??= $this->place;
        $category = MenuCategory::query()->firstOrCreate(
            ['restaurant_id' => $place->id, 'name_ar' => $section],
            ['name_en' => $section, 'sort_order' => 0, 'is_active' => true],
        );

        return Product::query()->create([
            'restaurant_id' => $place->id,
            'menu_category_id' => $category->id,
            'name_ar' => $name,
            'name_en' => $name,
            'price' => 100,
            'is_available' => true,
        ]);
    }

    private function actingAsPlace(): static
    {
        Sanctum::actingAs($this->place->owner);

        return $this->withHeaders(['X-App' => 'restaurant']);
    }

    private function actingAsAdmin(): static
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        return $this->withHeaders(['X-App' => 'admin']);
    }

    public function test_the_migration_leaves_one_fallback_named_other(): void
    {
        $this->assertSame(1, Cuisine::query()->where('is_fallback', true)->count());
        $this->assertSame('أخرى', $this->fallback()->name_ar);
    }

    public function test_what_a_dish_is_beats_what_it_is_made_of(): void
    {
        $this->assertSame('pizza', CuisineClassifier::guess('تشيكن رانش', null, 'بيتزا'));
        $this->assertSame('burger', CuisineClassifier::guess('تشيكن كلاسيك', null, 'برجر الدجاج'));
        $this->assertSame('sandwich', CuisineClassifier::guess('سندوتش كفتة', null, 'المشويات'));
        $this->assertSame('broast', CuisineClassifier::guess('بوكس تشيكن', null, 'وجبات بروستد فردية'));
        $this->assertSame('egyptian', CuisineClassifier::guess('صنية الصحبة الحلوة', null, 'ركن الصواني'));
        // Pasta has no filter, and saying so stops «مكرونة فراخ» from reading as chicken.
        $this->assertSame(CuisineClassifier::OTHER, CuisineClassifier::guess('مكرونة نجرسكو بالفراخ', null, 'ركن المكرونات'));
        // A section of extras is never a filter, whatever the extra is called.
        $this->assertSame(CuisineClassifier::OTHER, CuisineClassifier::guess('قطعة دجاج', null, 'الإضافات'));
        // Whole words only: «بطاطس» is not «بط».
        $this->assertNull(CuisineClassifier::guess('بطاطس محمرة', null, 'المقبلات'));
    }

    public function test_a_dish_saved_without_a_cuisine_is_filed_by_its_name(): void
    {
        $this->assertSame($this->c['pizza']->id, $this->dish('مارجريتا', 'بيتزا')->cuisine_id);
        $this->assertSame($this->c['grill']->id, $this->dish('كفتة', 'الأطباق')->cuisine_id);
        $this->assertSame($this->fallback()->id, $this->dish('بطاطس محمرة', 'مقبلات')->cuisine_id);
    }

    public function test_a_shop_that_is_not_a_kitchen_files_everything_under_other(): void
    {
        $pharmacy = StoreCategory::query()->create(['name_ar' => 'صيدليات', 'name_en' => 'Pharmacies', 'slug' => 'pharmacies', 'is_active' => true]);
        $shop = $this->makeRestaurant('صيدلية');
        $shop->update(['store_category_id' => $pharmacy->id]);

        $this->assertSame($this->fallback()->id, $this->dish('بيتزا مجمدة', 'مجمدات', $shop->fresh())->cuisine_id);
    }

    public function test_the_customer_is_offered_only_cuisines_that_hold_a_dish_and_never_other(): void
    {
        $this->dish('مارجريتا', 'بيتزا');
        $this->dish('بطاطس', 'مقبلات');

        $ids = array_column($this->getJson('/api/v1/cuisines')->assertOk()->json('data'), 'id');

        $this->assertSame([$this->c['pizza']->id], $ids);
        $this->getJson("/api/v1/cuisines/{$this->fallback()->id}/products")->assertNotFound();
    }

    public function test_a_cuisine_lists_its_dishes_from_every_place_each_naming_its_place(): void
    {
        $other = $this->makeRestaurant('تاج محل');
        $this->dish('مارجريتا', 'بيتزا');
        $this->dish('بيتزا فراخ', 'ركن البيتزا', $other);
        $this->dish('كولا', 'بيتزا'); // the dish's own name wins: a drink, not a pizza
        $this->dish('برجر لحم', 'برجر');

        $rows = $this->getJson("/api/v1/cuisines/{$this->c['pizza']->id}/products")->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['مارجريتا', 'بيتزا فراخ'], array_column($rows, 'name'));
        $this->assertEqualsCanonicalizing(['رملة', 'تاج محل'], array_values(array_unique(array_column($rows, 'restaurant_name'))));
    }

    public function test_the_place_list_filters_by_what_it_serves(): void
    {
        $grillHouse = $this->makeRestaurant('مشويات الشيخ');
        $this->dish('برجر', 'وجبات', $grillHouse);

        $ids = array_column($this->getJson("/api/v1/restaurants?filter[cuisine_id]={$this->c['burger']->id}")->assertOk()->json('data'), 'id');

        $this->assertSame([$grillHouse->id], $ids);
    }

    public function test_a_place_chooses_a_cuisine_from_the_shared_list_with_other_last(): void
    {
        $rows = $this->actingAsPlace()->getJson('/api/v1/restaurant/cuisines')->assertOk()->json('data');

        $this->assertTrue(end($rows)['is_fallback']);
        $this->assertCount(count($this->c) + 1, $rows);
    }

    public function test_a_place_files_a_dish_under_the_cuisine_it_chose(): void
    {
        $section = MenuCategory::query()->create(['restaurant_id' => $this->place->id, 'name_ar' => 'بيتزا', 'name_en' => 'Pizza', 'is_active' => true]);

        $id = $this->actingAsPlace()->postJson('/api/v1/restaurant/products', [
            'menu_category_id' => $section->id,
            'cuisine_id' => $this->c['chicken']->id,
            'name_ar' => 'بيتزا فراخ', 'name_en' => 'Chicken pizza', 'price' => 150,
        ])->assertCreated()->json('data.id');

        $this->assertSame($this->c['chicken']->id, Product::query()->find($id)->cuisine_id, 'the choice beats the guess');

        // Clearing it files the dish under «أخرى», never under nothing.
        $this->actingAsPlace()->patchJson("/api/v1/restaurant/products/{$id}", ['cuisine_id' => null])->assertOk();
        $this->assertSame($this->fallback()->id, Product::query()->find($id)->cuisine_id);

        // A cuisine that is switched off cannot be chosen.
        $this->c['burger']->update(['is_active' => false]);
        $this->actingAsPlace()->patchJson("/api/v1/restaurant/products/{$id}", ['cuisine_id' => $this->c['burger']->id])
            ->assertUnprocessable()->assertJsonValidationErrors('cuisine_id');
    }

    public function test_a_new_cuisine_needs_a_name_and_a_picture_and_is_offered_once_it_holds_a_dish(): void
    {
        $this->actingAsPlace()->post('/api/v1/restaurant/cuisines', ['name_ar' => 'مكرونة'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->actingAsPlace()->post('/api/v1/restaurant/cuisines', ['image' => UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('name_ar');

        $created = $this->actingAsPlace()->post('/api/v1/restaurant/cuisines', [
            'name_ar' => 'مكرونة', 'image' => UploadedFile::fake()->image('pasta.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $pasta = Cuisine::query()->findOrFail($created->json('data.id'));
        $this->assertSame('مكرونة', $pasta->name_en, 'the English name defaults to the Arabic');
        $this->assertSame($this->place->id, $pasta->restaurant_id);
        $this->assertNotNull($pasta->image);

        // «مكرونه» is the same cuisine — the second writer gets the first one's row.
        $again = $this->actingAsPlace()->post('/api/v1/restaurant/cuisines', [
            'name_ar' => 'مكرونه', 'image' => UploadedFile::fake()->image('again.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame($pasta->id, $again->json('data.id'));
        $this->assertTrue($again->json('meta.existing'));

        $this->assertNotContains($pasta->id, array_column($this->getJson('/api/v1/cuisines')->json('data'), 'id'));
        $this->dish('نجرسكو', 'باستا')->update(['cuisine_id' => $pasta->id]);
        $this->assertContains($pasta->id, array_column($this->getJson('/api/v1/cuisines')->json('data'), 'id'));
    }

    public function test_an_admin_creates_a_cuisine_with_a_picture_and_files_a_dish_under_it(): void
    {
        $this->actingAsAdmin()->postJson('/api/v1/admin/cuisines', ['name_ar' => 'سوشي'])
            ->assertUnprocessable()->assertJsonValidationErrors('image');

        $id = $this->actingAsAdmin()->post('/api/v1/admin/cuisines', [
            'name_ar' => 'سوشي', 'name_en' => 'Sushi', 'image' => UploadedFile::fake()->image('s.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $dish = $this->dish('ماكي', 'سوشي');
        $this->actingAsAdmin()->patchJson("/api/v1/admin/restaurants/{$this->place->id}/products/{$dish->id}", ['cuisine_id' => $id])->assertOk();
        $this->assertSame($id, $dish->fresh()->cuisine_id);

        $row = collect($this->actingAsAdmin()->getJson('/api/v1/admin/cuisines')->json('data'))->firstWhere('id', $id);
        $this->assertSame(1, $row['products_count']);
    }

    public function test_deleting_a_cuisine_moves_its_dishes_to_other_and_other_cannot_be_deleted(): void
    {
        $dish = $this->dish('مارجريتا', 'بيتزا');

        $this->actingAsAdmin()->deleteJson("/api/v1/admin/cuisines/{$this->c['pizza']->id}")->assertOk();
        $this->assertSame($this->fallback()->id, $dish->fresh()->cuisine_id);

        $this->actingAsAdmin()->deleteJson("/api/v1/admin/cuisines/{$this->fallback()->id}")
            ->assertStatus(409)->assertJsonPath('error', 'cuisine_is_fallback');
    }

    public function test_the_classify_command_files_only_dishes_nobody_filed(): void
    {
        $chosen = $this->dish('كولا', 'مشروبات');
        $chosen->update(['cuisine_id' => $this->c['burger']->id]);
        $unfiled = $this->dish('مارجريتا', 'بيتزا');
        Product::query()->whereKey($unfiled->id)->update(['cuisine_id' => null]);

        $this->artisan('cr7:classify-products', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($unfiled->fresh()->cuisine_id);

        $this->artisan('cr7:classify-products')->assertSuccessful();
        $this->assertSame($this->c['pizza']->id, $unfiled->fresh()->cuisine_id);
        $this->assertSame($this->c['burger']->id, $chosen->fresh()->cuisine_id, 'a choice is never overwritten');
    }
}
