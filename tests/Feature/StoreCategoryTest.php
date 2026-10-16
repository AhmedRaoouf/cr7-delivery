<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\StoreCategory;
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
 * The home screen's top strip, and the fact that the operator owns it.
 *
 * «الأدمين ممكن يضيف اللي هو عايزه… ممكن يضيف كمان واحدة مثلاً فواكه بدل خضروات، بنزينات،
 * أي حاجة من دي… لما تتضاف من الداشبورد، تقوم جاية هنا في التطبيق». So the thing under
 * test is not the five that ship — it is the sixth, added at runtime.
 */
class StoreCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
        Storage::fake('public');
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);
        $this->withHeader('X-App', 'admin');

        return $admin;
    }

    public function test_an_operator_adds_a_category_and_the_app_sees_it(): void
    {
        $this->actingAsAdmin();

        $created = $this->post('/api/v1/admin/store-categories', [
            'name_ar' => 'محطات بنزين',
            'name_en' => 'Petrol stations',
            'color' => '#F59E0B',
            'sort_order' => 9,
            'image' => UploadedFile::fake()->image('petrol.png', 600, 400),
        ]);

        $created->assertCreated();
        $this->assertSame('petrol-stations', $created->json('data.slug'));
        $this->assertNotNull($created->json('data.image'));

        $this->withHeader('X-App', 'app')
            ->getJson('/api/v1/store-categories')
            ->assertOk()
            ->assertJsonPath('data.0.name_ar', 'محطات بنزين');
    }

    /**
     * `Str::slug` drops Arabic entirely, so two categories named only in Arabic both
     * slug to the empty string and the second one collides on a unique index.
     */
    public function test_two_categories_named_only_in_arabic_do_not_collide(): void
    {
        $this->actingAsAdmin();

        $first = $this->postJson('/api/v1/admin/store-categories', ['name_ar' => 'فواكه', 'name_en' => 'فواكه']);
        $second = $this->postJson('/api/v1/admin/store-categories', ['name_ar' => 'خضار', 'name_en' => 'خضار']);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertNotSame($first->json('data.slug'), $second->json('data.slug'));
    }

    public function test_a_bad_colour_is_refused_rather_than_reaching_the_app(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/store-categories', [
            'name_ar' => 'كافيهات', 'name_en' => 'Cafes', 'color' => 'brand yellow',
        ])->assertStatus(422);
    }

    /**
     * A soft delete does not fire `nullOnDelete`, so the shops kept pointing at a row
     * the app no longer lists — which is how a restaurant leaves the home screen with
     * nobody touching it.
     */
    public function test_deleting_a_category_sets_its_shops_loose(): void
    {
        $this->actingAsAdmin();

        $category = StoreCategory::query()->create([
            'slug' => 'pharmacies', 'name_ar' => 'صيدليات', 'name_en' => 'Pharmacies',
        ]);
        $restaurant = $this->makeRestaurant('صيدلية عمرو');
        $restaurant->update(['store_category_id' => $category->id]);

        $this->deleteJson("/api/v1/admin/store-categories/{$category->id}")->assertOk();

        $this->assertNull($restaurant->fresh()->store_category_id);
        $this->assertSoftDeleted('store_categories', ['id' => $category->id]);
    }

    public function test_reordering_is_one_request_for_the_whole_strip(): void
    {
        $this->actingAsAdmin();

        $ids = collect(['مطاعم', 'صيدليات', 'كافيهات'])
            ->map(fn (string $name, int $i) => StoreCategory::query()->create([
                'slug' => 'cat-'.$i, 'name_ar' => $name, 'name_en' => $name, 'sort_order' => $i,
            ])->id)
            ->all();

        $this->postJson('/api/v1/admin/store-categories-reorder', ['ids' => array_reverse($ids)])->assertOk();

        $this->assertSame(
            array_reverse($ids),
            StoreCategory::query()->orderBy('sort_order')->pluck('id')->all(),
        );
    }

    /** «مفتوح 24 ساعة» is the default, and it wins over a stale week of hours. */
    public function test_a_restaurant_is_open_around_the_clock_unless_it_says_otherwise(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد')->fresh();

        $this->assertTrue($restaurant->is_24_7);

        // A week that says «مغلق كل يوم», which used to be the whole answer.
        foreach (range(0, 6) as $weekday) {
            $restaurant->openingHours()->create(['weekday' => $weekday, 'is_closed' => true]);
        }

        $this->assertTrue($restaurant->fresh()->isOpenNow());

        $restaurant->update(['is_24_7' => false]);
        $this->assertFalse($restaurant->fresh()->isOpenNow());
    }

    public function test_the_dashboard_switch_writes_the_flag_and_keeps_the_week(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');
        $restaurant->update(['is_24_7' => false]);
        $restaurant->openingHours()->create([
            'weekday' => 0, 'opens_at' => '10:00:00', 'closes_at' => '23:00:00', 'is_closed' => false,
        ]);

        Sanctum::actingAs($restaurant->owner);
        $this->withHeader('X-App', 'restaurant');

        // Switching «24 ساعة» on must not need seven rows of times, and must not
        // destroy the week somebody spent typing — switching back has to find it.
        $this->putJson('/api/v1/restaurant/opening-hours', ['is_24_7' => true])
            ->assertOk()
            ->assertJsonPath('data.is_24_7', true);

        $this->assertTrue($restaurant->fresh()->is_24_7);
        $this->assertSame(1, $restaurant->openingHours()->count());
        $this->assertSame('10:00:00', $restaurant->openingHours()->first()->opens_at);
    }

    public function test_the_week_the_api_handed_out_can_be_sent_back_with_its_seconds(): void
    {
        $restaurant = $this->makeRestaurant('لا ڤي');

        Sanctum::actingAs($restaurant->owner);
        $this->withHeader('X-App', 'restaurant');

        $week = fn (bool $closed) => array_map(fn (int $d) => [
            'weekday' => $d, 'opens_at' => '08:00:00', 'closes_at' => '04:00:00',
            'is_closed' => $closed, 'is_24h' => false,
        ], range(0, 6));

        // «08:00:00» is what GET answers; a form that echoes it must not be refused.
        $this->putJson('/api/v1/restaurant/opening-hours', ['is_24_7' => false, 'hours' => $week(true)])
            ->assertOk();
        $this->putJson('/api/v1/restaurant/opening-hours', ['is_24_7' => false, 'hours' => $week(false)])
            ->assertOk();

        $this->assertSame('08:00:00', $restaurant->openingHours()->where('weekday', 3)->first()->opens_at);
        $this->assertFalse((bool) $restaurant->openingHours()->where('weekday', 3)->first()->is_closed);

        $this->putJson('/api/v1/restaurant/opening-hours', ['is_24_7' => false, 'hours' => array_map(
            fn ($r) => ['opens_at' => '25:00'] + $r, $week(false),
        )])->assertStatus(422);
    }
}
