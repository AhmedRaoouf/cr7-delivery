<?php

namespace Tests\Feature;

use App\Actions\Admin\CreateAdminUserAction;
use App\Models\User;
use App\Support\AdminScope;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-51 — «a permission says *what*, a geographic scope says *whose rows*, and both must
 * pass». An authority that arrives unspecified must therefore be **none**, not **all**.
 *
 * `CreateAdminUserAction` defaulted to global. Three ways to reach that default, and the
 * third is the one that happened on the live API: a body with no `scope`, a `scope`
 * carrying only `city_ids`, and a client sending `type` instead of `scope_type` — because
 * `POST /auth/login` publishes the scope as `{"type": "city", …}` and this endpoint
 * accepted only the other spelling. A silent typo minted an admin who could see every
 * city on the platform, and nothing in the response said so.
 */
class AdminScopeCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedLocation();
        $this->seed([PermissionsSeeder::class, RolesSeeder::class]);
    }

    public function test_the_published_spelling_is_accepted(): void
    {
        // Exactly the shape `POST /auth/login` hands back.
        $user = $this->create(['type' => 'city', 'city_ids' => [$this->city->id]]);

        $this->assertFalse(AdminScope::isGlobal($user));
        $this->assertSame([$this->city->id], AdminScope::scopedCityIds($user));
    }

    public function test_the_documented_spelling_still_works(): void
    {
        $user = $this->create(['scope_type' => 'city', 'city_ids' => [$this->city->id]]);

        $this->assertFalse(AdminScope::isGlobal($user));
        $this->assertSame([$this->city->id], AdminScope::scopedCityIds($user));
    }

    public function test_a_scope_that_never_arrived_grants_nothing(): void
    {
        $user = $this->create([]);

        // Not global, and not scoped to anything: this admin sees an empty platform and
        // has to be scoped deliberately. That is the failure the operator notices in a
        // minute, instead of the one nobody notices at all.
        $this->assertFalse(AdminScope::isGlobal($user));
        $this->assertSame([], AdminScope::scopedCityIds($user));
    }

    public function test_only_ids_without_a_type_are_still_taken_as_a_city_scope(): void
    {
        $user = $this->create(['city_ids' => [$this->city->id]]);

        $this->assertFalse(AdminScope::isGlobal($user));
        $this->assertSame([$this->city->id], AdminScope::scopedCityIds($user));
    }

    public function test_global_is_granted_when_it_is_actually_asked_for(): void
    {
        $this->assertTrue(AdminScope::isGlobal($this->create(['type' => 'global'])));
        $this->assertTrue(AdminScope::isGlobal($this->create(['scope_type' => 'global'], '01000000078')));
    }

    public function test_a_super_admin_is_global_whatever_the_body_says(): void
    {
        // The one role whose authority is global by definition (doc 24 §1.2), so a body
        // that narrows it is ignored rather than obeyed.
        $user = $this->create(['type' => 'city', 'city_ids' => [$this->city->id]], '01000000077', 'super_admin');

        $this->assertTrue(AdminScope::isGlobal($user));
    }

    /** @param  array<string, mixed>  $scope */
    private function create(array $scope, string $phone = '01000000079', string $role = 'support'): User
    {
        $actor = User::query()->firstOrCreate(
            ['phone' => '01000000099'],
            ['name' => 'Admin', 'type' => 'admin', 'is_active' => true],
        );
        $actor->assignRole('super_admin');

        return app(CreateAdminUserAction::class)->execute($actor, [
            'name' => 'مشرف',
            'phone' => $phone,
            'role' => $role,
            'scope' => $scope,
        ])['user'];
    }
}
