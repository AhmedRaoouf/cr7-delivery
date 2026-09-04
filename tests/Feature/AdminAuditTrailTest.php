<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D-52 — «every admin action on a row writes an `audit_logs` row».
 *
 * It did not. Sixteen admin controllers mutated rows in silence, including the two that
 * decide who can do what (`RoleController`) and what a restaurant is charged
 * (`RestaurantController`). `AuditableObserver` closes that on the model event rather
 * than on the fifty call sites, and these tests pin the three properties that make the
 * difference between a log and a liability: it fires for an admin, it does **not** fire
 * for a tenant editing their own rows, and it never copies a credential.
 */
class AdminAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
    }

    public function test_an_admin_editing_a_restaurant_leaves_a_row_with_both_sides_of_the_change(): void
    {
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        Sanctum::actingAs($this->makeAdmin());

        // The commission endpoint, not `update`: `commission_rate` is money, it has its
        // own route and its own permission, and it is the change an operator is most
        // likely to be asked about six months later.
        $this->withHeader('X-App', 'admin')
            ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/commission", ['commission_rate' => 18])
            ->assertOk();

        $log = AuditLog::query()->where('action', 'restaurants.updated')->firstOrFail();

        $this->assertSame(Restaurant::class, $log->auditable_type);
        $this->assertSame($restaurant->id, (int) $log->auditable_id);
        // Both sides. «It changed» is not an audit trail; «from 10 to 18» is.
        // Compared as numbers: the old value comes back from MySQL as the decimal string
        // `10.00` and the new one is the integer the request carried.
        $this->assertEquals(10, $log->old_values['commission_rate']);
        $this->assertEquals(18, $log->new_values['commission_rate']);
        // The request is part of the record, not only the intent.
        $this->assertNotNull($log->ip);
    }

    public function test_a_restaurant_editing_its_own_menu_writes_nothing(): void
    {
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        $product = $this->makeProduct($restaurant, 'سندوتش', 40);

        Sanctum::actingAs($restaurant->owner);

        $this->withHeader('X-App', 'restaurant')
            ->patchJson("/api/v1/restaurant/products/{$product->id}", ['price' => 45])
            ->assertOk();

        // D-52 is about the platform's staff acting on other people's rows. A tenant
        // using its own dashboard goes through the same model and must not fill the
        // admin trail with noise that hides the entries that matter.
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_a_password_reset_records_that_it_happened_and_never_the_password(): void
    {
        $restaurant = $this->makeRestaurant('سندوتش عمر');
        Sanctum::actingAs($this->makeAdmin());

        $this->withHeader('X-App', 'admin')
            ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/reset-password")
            ->assertOk();

        $log = AuditLog::query()->where('action', 'restaurants.reset_password')->firstOrFail();

        $this->assertSame($restaurant->owner->id, $log->new_values['owner_user_id']);

        // A hash is still a credential, and `audit_logs` is read by more people than
        // `users` is. Nothing in any row may carry one.
        foreach (AuditLog::query()->get() as $row) {
            $this->assertArrayNotHasKey('password', (array) $row->new_values);
            $this->assertArrayNotHasKey('password', (array) $row->old_values);
        }
    }

    public function test_granting_a_permission_is_recorded_even_though_it_writes_a_pivot(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $role = Role::query()->where('name', 'support')->firstOrFail();

        $this->withHeader('X-App', 'admin')
            ->patchJson("/api/v1/admin/roles/{$role->id}", ['permissions' => ['restaurants.view', 'orders.view']])
            ->assertStatus(422); // seeded roles are not editable — clone first

        $clone = $this->withHeader('X-App', 'admin')
            ->postJson("/api/v1/admin/roles/{$role->id}/clone", [
                'name' => 'support_v2',
                'label_ar' => 'الدعم ٢',
                'label_en' => 'Support 2',
            ])->assertCreated()->json('data.id');

        $this->withHeader('X-App', 'admin')
            ->patchJson("/api/v1/admin/roles/{$clone}", ['permissions' => ['restaurants.view']])
            ->assertOk();

        $log = AuditLog::query()->where('action', 'roles.permissions')->latest('id')->firstOrFail();

        // `syncPermissions()` writes `role_has_permissions`, so no model event fires on
        // `Role` and the observer alone would record a row that says nothing about the
        // only field anyone would audit a role for.
        $this->assertSame(['restaurants.view'], $log->new_values['permissions']);
        $this->assertNotEmpty($log->old_values['permissions']);
    }

    public function test_cloning_a_role_cannot_copy_a_permission_the_actor_does_not_hold(): void
    {
        // An admin who may manage roles but holds almost nothing else — the exact shape
        // D-51 exists for. `support` alone cannot reach the endpoint at all, and a 403 from
        // the permission middleware would prove nothing about the guard inside it.
        $narrow = Role::query()->create([
            'name' => 'role_manager',
            'guard_name' => 'web',
            'label_ar' => 'مدير الأدوار',
            'label_en' => 'Role manager',
            'is_system' => false,
        ]);
        $narrow->syncPermissions(['roles.manage', 'roles.create', 'restaurants.view']);

        $limited = User::query()->create([
            'name' => 'مشرف أدوار',
            'phone' => '01000000077',
            'type' => 'admin',
            'is_active' => true,
        ]);
        $limited->assignRole($narrow);
        $limited->adminScopes()->create(['scope_type' => \App\Enums\AdminScopeType::Global]);

        Sanctum::actingAs($limited);

        $superAdmin = Role::query()->where('name', 'super_admin')->firstOrFail();

        // D-51. `store` and `update` both asserted this and `clone` did not, so the one
        // endpoint that copies a permission set wholesale copied it unchecked: a holder
        // of `roles.manage` could mint a role carrying every permission on the platform.
        $this->withHeader('X-App', 'admin')
            ->postJson("/api/v1/admin/roles/{$superAdmin->id}/clone", [
                'name' => 'everything',
                'label_ar' => 'كل شيء',
                'label_en' => 'Everything',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error', 'permission_escalation');

        $this->assertNull(Role::query()->where('name', 'everything')->first());
    }

    private function makeAdmin(): User
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'phone' => '01000000099',
            'type' => 'admin',
            'is_active' => true,
        ]);

        $admin->assignRole('super_admin');
        $admin->adminScopes()->create(['scope_type' => \App\Enums\AdminScopeType::Global]);

        return $admin;
    }
}
