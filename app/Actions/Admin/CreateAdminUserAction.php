<?php

namespace App\Actions\Admin;

use App\Enums\AdminScopeType;
use App\Enums\UserType;
use App\Models\User;
use App\Support\PermissionEscalationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** doc 24 §1.5 — the account is created inactive with a random password; must_change_password = true. */
class CreateAdminUserAction
{
    /** @return array{user: User, temp_password: string} */
    public function execute(User $actor, array $data): array
    {
        PermissionEscalationGuard::assertCanGrantRoles($actor, [$data['role']]);
        PermissionEscalationGuard::assertCanGrantPermissions($actor, $data['grants'] ?? []);

        return DB::transaction(function () use ($actor, $data) {
            $tempPassword = Str::password(12);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'password' => Hash::make($tempPassword),
                'type' => UserType::Admin,
                'is_active' => true,
                'must_change_password' => true,
            ]);

            $user->assignRole($data['role']);

            if (! empty($data['grants'])) {
                $user->givePermissionTo($data['grants']);
            }

            $user->adminProfile()->create([
                'job_title' => $data['job_title'] ?? null,
                'invited_by' => $actor->id,
                'invited_at' => now(),
                'must_change_password' => true,
            ]);

            $this->applyScope($user, $data['scope'] ?? [], $data['role']);

            return ['user' => $user->fresh(), 'temp_password' => $tempPassword];
        });
    }

    /**
     * D-51 — «a permission says *what*, a geographic scope says *whose rows*, and both
     * must pass».
     *
     * This used to fall back to **global** whenever the caller did not name a scope type,
     * which is the widest authority the platform has and the exact opposite of what an
     * unspecified value should mean. Three ways to reach it, none of them deliberate: a
     * body with no `scope` at all, a `scope` carrying only `city_ids`, and — the one that
     * actually happened — a client sending `type` instead of `scope_type`, because
     * `POST /auth/login` *publishes* the scope as `{"type": "city", ...}` and this endpoint
     * accepted only the other spelling. A silent typo minted a global admin.
     *
     * Both spellings are read now, and an unrecognised scope creates **no rows at all**.
     * `AdminScope::isGlobal()` is false without a global row and every scoped list is
     * empty without a city row, so an admin whose scope did not arrive sees nothing and
     * says so, instead of seeing everything and saying nothing.
     */
    private function applyScope(User $user, array $scope, string $role): void
    {
        // The one role whose authority is global by definition (doc 24 §1.2).
        if ($role === 'super_admin') {
            $user->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

            return;
        }

        $type = $scope['scope_type'] ?? $scope['type'] ?? null;

        if ($type === 'global') {
            $user->adminScopes()->create(['scope_type' => AdminScopeType::Global]);

            return;
        }

        foreach ($scope['city_ids'] ?? [] as $cityId) {
            $user->adminScopes()->create(['scope_type' => AdminScopeType::City, 'city_id' => $cityId]);
        }

        foreach ($scope['zone_ids'] ?? [] as $zoneId) {
            $user->adminScopes()->create(['scope_type' => AdminScopeType::Zone, 'zone_id' => $zoneId]);
        }
    }
}
