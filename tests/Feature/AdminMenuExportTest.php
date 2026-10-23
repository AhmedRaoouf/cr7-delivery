<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/** «تصدير إكسل للمنيو أقسام ومنتجات» — `ADM-13` as a workbook. */
class AdminMenuExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
        $this->withHeader('X-App', 'admin');
    }

    private function admin(string $role = 'super_admin'): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole($role);
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** @return array<string, string> */
    private function unzip(string $body): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $body);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return $entries;
    }

    public function test_the_workbook_holds_categories_dishes_and_options_of_that_place_only(): void
    {
        $this->admin();
        $box = $this->makeRestaurant('بوكس');
        $other = $this->makeRestaurant('مكان آخر');
        $dish = $this->makeProduct($box, 'برجر لحم', 85);
        $this->makeProduct($other, 'صنف غريب', 10);
        $group = $dish->optionGroups()->create(['name_ar' => 'الحجم', 'name_en' => 'Size', 'selection_type' => 'single', 'price_mode' => 'add', 'is_required' => true]);
        $group->options()->create(['name_ar' => 'كبير', 'name_en' => 'Large', 'price_delta' => 15]);

        $response = $this->get("/api/v1/admin/restaurants/{$box->id}/menu/export")->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('Content-Type'));

        $entries = $this->unzip($response->streamedContent());
        $text = ($entries['xl/sharedStrings.xml'] ?? '').implode('', array_filter($entries, fn ($k) => str_starts_with($k, 'xl/worksheets/'), ARRAY_FILTER_USE_KEY));
        $this->assertStringContainsString('برجر لحم', $text);
        $this->assertStringContainsString('القائمة', $text);
        $this->assertStringContainsString('كبير', $text);
        $this->assertStringNotContainsString('صنف غريب', $text);
        $this->assertStringContainsString('xl/worksheets/sheet3.xml', implode(',', array_keys($entries)));
    }

    public function test_it_needs_the_products_view_permission(): void
    {
        $user = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($user);
        $box = $this->makeRestaurant('بوكس');

        $this->get("/api/v1/admin/restaurants/{$box->id}/menu/export")->assertForbidden();
    }
}
