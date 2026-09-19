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
 * `GET /admin/dashboard/charts` — `peak_hours` is a map of hour → count, and the dashboards'
 * schema reads it as one. A keyed collection that is empty (or runs 0, 1, 2 …) serialises as a
 * JSON *list*, and the admin home threw «Expected object, received array».
 */
class AdminChartsPeakHoursShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_peak_hours_is_an_object_even_when_there_are_no_orders(): void
    {
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);

        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        $response = $this->withHeader('X-App', 'admin')->getJson('/api/v1/admin/dashboard/charts');

        $response->assertOk();
        $this->assertStringContainsString('"peak_hours":{}', $response->getContent());
    }
}
