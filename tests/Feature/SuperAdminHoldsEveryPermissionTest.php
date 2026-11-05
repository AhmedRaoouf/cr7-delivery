<?php

namespace Tests\Feature;

use App\Enums\AdminScopeType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsListFixtures;
use Tests\TestCase;

/**
 * Operator, 2026-11-05: «أي super admin يبقا معاه كل الصلاحيات». The role is the
 * answer, not the rows a migration happened to attach to it.
 */
class SuperAdminHoldsEveryPermissionTest extends TestCase
{
    use BuildsListFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpListWorld();
    }

    private function forgetOnRole(string $permission): void
    {
        Role::findByName('super_admin', 'web')->revokePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_a_super_admin_opens_a_screen_whose_permission_the_role_does_not_carry(): void
    {
        $admin = $this->globalAdmin();
        $this->forgetOnRole('products.approve_prices');

        $this->assertFalse($admin->fresh()->hasPermissionTo('products.approve_prices'));
        $this->actingAsAdmin($admin->fresh())
            ->getJson('/api/v1/admin/price-requests')
            ->assertOk();
    }

    public function test_auth_me_publishes_the_whole_catalogue_for_a_super_admin(): void
    {
        $admin = $this->globalAdmin();
        Permission::create(['name' => 'brand.new_screen', 'guard_name' => 'web']);
        $this->forgetOnRole('products.approve_prices');

        $permissions = $this->actingAsAdmin($admin->fresh())
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->json('data.permissions');

        $this->assertContains('brand.new_screen', $permissions);
        $this->assertContains('products.approve_prices', $permissions);
        $this->assertCount(Permission::count(), $permissions);
    }

    public function test_any_other_role_still_gets_only_what_it_was_given(): void
    {
        $ops = User::query()->create([
            'name' => 'تشغيل',
            'phone' => '01000000088',
            'type' => 'admin',
            'is_active' => true,
        ]);
        $ops->assignRole('operations');
        $ops->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

        $this->assertFalse($ops->can('products.approve_prices'));
        $this->actingAsAdmin($ops)
            ->getJson('/api/v1/admin/price-requests')
            ->assertForbidden();
        $this->assertNotContains(
            'products.approve_prices',
            $this->actingAsAdmin($ops)->getJson('/api/v1/auth/me')->json('data.permissions'),
        );
    }

    public function test_only_permission_names_pass_not_any_ability(): void
    {
        $admin = $this->globalAdmin();

        $this->assertTrue($admin->can('products.approve_prices'));
        $this->assertFalse($admin->can('not-a-permission'));
    }
}
