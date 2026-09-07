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

/**
 * «زرار طباعة إكسل» on every report.
 *
 * Two things are worth a test here and nothing else is: that the bytes coming back are
 * actually a workbook rather than a JSON error with a 200 on it, and that the permission
 * on the export is the same one on the report beside it. An export that answers to a
 * weaker permission than its own screen is a data leak with a download link.
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
        $this->withHeader('X-App', 'admin');
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        return $admin;
    }

    public function test_every_report_downloads_as_a_real_workbook(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $reports = ['sales', 'restaurants', 'products', 'drivers', 'zones', 'promo-codes', 'refunds', 'complaints', 'customers'];

        foreach ($reports as $report) {
            $response = $this->get("/api/v1/admin/reports/{$report}/export");

            $response->assertOk();
            $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));

            // An xlsx is a zip. `PK` is the whole check that matters — a JSON error
            // rendered with a 200 and the right content type would pass everything else.
            $body = $response->streamedContent();
            $this->assertSame('PK', substr($body, 0, 2), "{$report} did not stream a workbook");
        }
    }

    public function test_an_export_answers_to_the_same_permission_as_its_report(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        // Given the sales report only. Every other export must refuse them.
        $admin->givePermissionTo('reports.sales');
        Sanctum::actingAs($admin);

        $this->get('/api/v1/admin/reports/sales/export')->assertOk();
        $this->get('/api/v1/admin/reports/drivers/export')->assertForbidden();
        $this->get('/api/v1/admin/reports/customers/export')->assertForbidden();
    }

    public function test_a_restaurant_downloads_its_own_sales(): void
    {
        $restaurant = $this->makeRestaurant('ابن البلد');

        Sanctum::actingAs($restaurant->owner);
        $this->withHeader('X-App', 'restaurant');

        $response = $this->get('/api/v1/restaurant/reports/export');
        $response->assertOk();
        $this->assertSame('PK', substr($response->streamedContent(), 0, 2));
    }
}
