<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every map an admin endpoint publishes (key → count) is a JSON **object**, even when it is
 * empty. A keyed collection with no rows — or with keys 0, 1, 2 … — serialises as a JSON
 * *list*, and the dashboards' `z.record` refuses a list: the whole screen fails.
 *
 * `peak_hours` was fixed alone (doc 72); `statuses` and `payment_methods` sat beside it with
 * the same defect, and on 2026-10-10 — a week with no orders — the admin home answered
 * «تعذر تحميل هذه الشاشة». A new map on any of these endpoints belongs in this list.
 */
class AdminMapsAreObjectsWhenEmptyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, list<string>}> */
    public static function maps(): array
    {
        return [
            'dashboard charts' => ['/api/v1/admin/dashboard/charts', ['statuses', 'payment_methods', 'peak_hours']],
            'refunds report' => ['/api/v1/admin/reports/refunds', ['by_reason']],
            'complaints report' => ['/api/v1/admin/reports/complaints', ['by_category']],
        ];
    }

    /** @param list<string> $keys */
    #[DataProvider('maps')]
    public function test_an_empty_map_is_an_object_not_a_list(string $uri, array $keys): void
    {
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);

        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        $response = $this->withHeader('X-App', 'admin')->getJson($uri);

        $response->assertOk();
        foreach ($keys as $key) {
            $this->assertStringContainsString("\"{$key}\":{}", $response->getContent(), "{$uri} → {$key}");
        }
    }
}
