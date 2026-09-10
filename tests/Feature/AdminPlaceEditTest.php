<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `ADM-12` — editing a place, and the two fields that never arrived.
 *
 * 🔴 Operator, 2026-09-10: «نسبة العمولة ومتوسط التحضير مش بتتسمع في الداشبورد لما
 * بعدّلها». Both were real and both were different faults.
 *
 * `commission_rate` was on the dashboard's form and had **no rule** in
 * `UpdateRestaurantRequest`, so `validated()` dropped it before the controller ever saw
 * it and the save answered 200 having changed nothing — the worst shape a bug can take,
 * because the operator is told it worked.
 *
 * `avg_prep_minutes` had the rule and no field: the API would have taken it, nothing
 * ever sent one, and a new place kept the column default of 20 minutes for ever.
 *
 * The rule the fix must not break is D-51's: `restaurants.set_commission` is its own
 * grant, and letting the commission ride in on `restaurants.update` would widen one
 * permission by way of another.
 */
class AdminPlaceEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class, SettingsSeeder::class]);
        $this->withHeader('X-App', 'admin');
    }

    private function actingAsSuperAdmin(): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** An admin who may edit a place but may not price the platform's cut. */
    private function actingAsEditorWithoutCommission(): User
    {
        $role = Role::findOrCreate('place_editor', 'web');
        $role->syncPermissions(['restaurants.view', 'restaurants.update']);

        $admin = User::factory()->create(['type' => 'admin']);
        $admin->assignRole($role);
        $admin->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_the_commission_the_form_sends_actually_lands(): void
    {
        $this->actingAsSuperAdmin();
        $place = $this->makeRestaurant('سندوتش عمر');
        $place->forceFill(['commission_rate' => 10])->save();

        $this->patchJson("/api/v1/admin/restaurants/{$place->id}", [
            'commission_rate' => 12.5,
        ])->assertOk();

        $this->assertEqualsWithDelta(12.5, (float) $place->fresh()->commission_rate, 0.001);
    }

    public function test_the_prep_time_the_form_sends_actually_lands(): void
    {
        $this->actingAsSuperAdmin();
        $place = $this->makeRestaurant('ابن البلد');

        $this->patchJson("/api/v1/admin/restaurants/{$place->id}", [
            'avg_prep_minutes' => 35,
        ])->assertOk();

        $this->assertSame(35, $place->fresh()->avg_prep_minutes);
    }

    public function test_a_prep_time_outside_the_window_is_refused(): void
    {
        $this->actingAsSuperAdmin();
        $place = $this->makeRestaurant('كنج بروست');

        $this->patchJson("/api/v1/admin/restaurants/{$place->id}", [
            'avg_prep_minutes' => 500,
        ])->assertStatus(422)->assertJsonValidationErrors('avg_prep_minutes');
    }

    public function test_an_editor_without_the_grant_may_still_save_the_rest_of_the_form(): void
    {
        $this->actingAsEditorWithoutCommission();
        $place = $this->makeRestaurant('صواني الدمياطي');
        $place->forceFill(['commission_rate' => 10])->save();

        // The dashboard posts the whole form on every save, commission included. A save
        // that leaves the number where it is is not a commission change, and refusing it
        // would lock this admin out of editing a phone number.
        $this->patchJson("/api/v1/admin/restaurants/{$place->id}", [
            'commission_rate' => 10,
            'avg_prep_minutes' => 25,
            'phone' => '01000000123',
        ])->assertOk();

        $this->assertSame(25, $place->fresh()->avg_prep_minutes);
    }

    public function test_an_editor_without_the_grant_cannot_move_the_commission(): void
    {
        $this->actingAsEditorWithoutCommission();
        $place = $this->makeRestaurant('بوكس ع السريع');
        $place->forceFill(['commission_rate' => 10])->save();

        // Refused **by name**, not silently trimmed (D-52), and in the doc 08 §1
        // envelope rather than Laravel's HTML error page — the dashboard reads the
        // former and would have shown «حدث خطأ غير متوقع» for the latter.
        $this->patchJson("/api/v1/admin/restaurants/{$place->id}", [
            'commission_rate' => 30,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error', 'commission_not_permitted');

        $this->assertEqualsWithDelta(10, (float) $place->fresh()->commission_rate, 0.001);
    }

    public function test_the_dedicated_endpoint_still_needs_its_own_grant(): void
    {
        $this->actingAsEditorWithoutCommission();
        $place = $this->makeRestaurant('ناصر القياس');

        $this->postJson("/api/v1/admin/restaurants/{$place->id}/commission", [
            'commission_rate' => 30,
        ])->assertForbidden();
    }
}
