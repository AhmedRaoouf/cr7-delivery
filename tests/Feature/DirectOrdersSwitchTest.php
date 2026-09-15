<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * doc 63 §6 — «حقل التفعيل مطلوب.», the operator's first screenshot.
 *
 * The mobile admin's «الطلبات المباشرة» switch on a place posted `{direct_orders_enabled:
 * true}` — the column's name — to an endpoint that validated `enabled`, so every press
 * answered 422 and changed nothing. The data source's test faked the response and never
 * looked at the request. The API now takes either name, because the installed 1.0.3 keeps
 * sending the one it has, and ignores the per-place cap it sends beside it: there is no
 * such column, the cap is the platform's setting.
 */
class DirectOrdersSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_switch_takes_either_name_and_ignores_a_cap(): void
    {
        $this->seedLocation();
        $place = $this->makeRestaurant('كنج بروست');
        $url = "/api/v1/admin/restaurants/{$place->id}/direct-orders";

        $this->asAdmin()->postJson($url, ['enabled' => true])->assertOk();
        $this->assertTrue((bool) $place->fresh()->direct_orders_enabled);

        // The installed 1.0.3 console: the column's name, and the cap beside it.
        $this->asAdmin()->postJson($url, ['direct_orders_enabled' => false, 'direct_orders_daily_cap' => 5])->assertOk();
        $this->assertFalse((bool) $place->fresh()->direct_orders_enabled);

        $this->asAdmin()->postJson($url, ['direct_orders_enabled' => true])->assertOk();
        $this->assertTrue((bool) $place->fresh()->direct_orders_enabled);

        // `false` is an answer, not a missing field.
        $this->asAdmin()->postJson($url, ['enabled' => false])->assertOk();
        $this->assertFalse((bool) $place->fresh()->direct_orders_enabled);
    }

    public function test_a_press_with_neither_name_is_refused_by_the_field(): void
    {
        $this->seedLocation();
        $place = $this->makeRestaurant('كنج بروست');

        $this->asAdmin()->postJson("/api/v1/admin/restaurants/{$place->id}/direct-orders", ['direct_orders_daily_cap' => 5])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure(['errors' => ['enabled']]);

        $this->assertFalse((bool) $place->fresh()->direct_orders_enabled);
    }

    private ?User $admin = null;

    private function asAdmin(): static
    {
        if ($this->admin === null) {
            $this->seed([PermissionsSeeder::class, RolesSeeder::class]);

            $this->admin = User::factory()->create(['type' => 'admin', 'is_active' => true]);
            $this->admin->assignRole('super_admin');
            $this->admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        }

        Sanctum::actingAs($this->admin);

        return $this->withHeaders(['X-App' => 'admin']);
    }
}
