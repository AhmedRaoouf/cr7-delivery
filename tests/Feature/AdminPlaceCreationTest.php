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
 * `ADM-12` — adding a place, with everything the form can send.
 *
 * 🔴 This exists because `CreateRestaurantAction` built its row from a
 * hand-written list of columns while `StoreRestaurantRequest` validated a
 * longer one. Five accepted fields were silently dropped — the store category,
 * both ends of the delivery window, `is_24_7`, and the `logo`/`cover` paths the
 * controller had *just* uploaded — so a place created through the dashboard
 * came back with no category, no window and no picture, and every layer
 * reported success. Operator, 2026-09-08: «جرّب تضيف مكان بإيدك وتحطّ صورة
 * واتأكد إن كل حاجة في الموضوع ده شغّالة».
 *
 * The assertions are deliberately field-by-field rather than a spot check: the
 * defect was never one field, it was the gap between two lists.
 */
class AdminPlaceCreationTest extends TestCase
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

    public function test_a_place_keeps_every_field_the_form_sent(): void
    {
        $this->actingAsAdmin();

        $category = StoreCategory::query()->create([
            'name_ar' => 'صيدليات',
            'name_en' => 'Pharmacies',
            'slug' => 'pharmacies',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $created = $this->post('/api/v1/admin/restaurants', [
            'city_id' => $this->city->id,
            'service_zone_id' => $this->zone->id,
            'store_category_id' => $category->id,
            'name_ar' => 'صيدلية النور',
            'name_en' => 'Elnour Pharmacy',
            'owner_phone' => '01033445566',
            'phone' => '0503334455',
            'address' => 'شارع البحر، جمصة',
            'latitude' => 31.4463,
            'longitude' => 31.5372,
            'commission_rate' => 15,
            'min_order_value' => 0,
            'eta_min_minutes' => 20,
            'eta_max_minutes' => 35,
            'logo' => UploadedFile::fake()->image('logo.png', 512, 512),
            'cover' => UploadedFile::fake()->image('cover.png', 1200, 600),
        ]);

        $created->assertCreated();

        $place = $created->json('data.restaurant');
        $this->assertSame($category->id, $place['store_category_id'], 'the category the home strip groups by');
        $this->assertSame(20, $place['eta_min_minutes']);
        $this->assertSame(35, $place['eta_max_minutes']);
        $this->assertSame($this->zone->id, $place['service_zone_id']);
        $this->assertSame('شارع البحر، جمصة', $place['address']);
        $this->assertNotNull($place['logo'], 'the uploaded logo was validated, uploaded — and then dropped');
        $this->assertNotNull($place['cover']);

        // The owner's login is the whole point of a place account, and it is
        // issued exactly once.
        $this->assertNotEmpty($created->json('data.owner_temp_password'));
        $this->assertDatabaseHas('users', [
            'phone' => '01033445566',
            'type' => 'restaurant',
            'must_change_password' => true,
        ]);
    }

    /**
     * The two fields whose absence made the form unsavable. They are `required`
     * on the API and were missing from `ADM-12` entirely, so the page answered
     * «حقل المنطقة مطلوب (and 1 more error)» with nowhere to fix either.
     */
    public function test_the_zone_and_the_owner_phone_are_required(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/restaurants', [
            'city_id' => $this->city->id,
            'name_ar' => 'مكان بلا منطقة',
            'name_en' => 'No zone',
            'phone' => '0503334455',
            'latitude' => 31.4463,
            'longitude' => 31.5372,
            'commission_rate' => 15,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_zone_id', 'owner_phone']);
    }
}
