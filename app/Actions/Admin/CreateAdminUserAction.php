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

            $scope = $data['scope'] ?? ['scope_type' => 'city', 'city_ids' => []];
            $this->applyScope($user, $scope, $data['role']);

            return ['user' => $user->fresh(), 'temp_password' => $tempPassword];
        });
    }

    private function applyScope(User $user, array $scope, string $role): void
    {
        $type = $role === 'super_admin' ? 'global' : ($scope['scope_type'] ?? 'global');

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
