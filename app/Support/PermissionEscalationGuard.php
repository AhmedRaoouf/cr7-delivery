<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Models\Role;
use App\Models\User;

/**
 * doc 24 §1.3 / doc 16 D-51 — an admin can never create, or grant, a permission or role
 * they do not themselves hold. Enforced by intersecting the submitted set with
 * auth()->user()->getAllPermissions(); a superset is rejected and names the offending
 * strings — never silently trimmed.
 */
class PermissionEscalationGuard
{
    /** @param  string[]  $permissions */
    public static function assertCanGrantPermissions(User $actor, array $permissions): void
    {
        if ($permissions === []) {
            return;
        }

        $held = $actor->getAllPermissions()->pluck('name')->all();
        $offending = array_values(array_diff($permissions, $held));

        if ($offending !== []) {
            throw new ApiException('permission_escalation', 'لا يمكنك منح صلاحيات لا تملكها: '.implode(', ', $offending), 403, [
                'permissions' => $offending,
            ]);
        }
    }

    /** @param  string[]  $roleNames */
    public static function assertCanGrantRoles(User $actor, array $roleNames): void
    {
        if ($roleNames === []) {
            return;
        }

        if ($actor->hasRole('super_admin')) {
            return;
        }

        $actorRoles = $actor->getRoleNames()->all();
        $offending = array_values(array_diff($roleNames, $actorRoles));

        if ($offending !== []) {
            throw new ApiException('permission_escalation', 'لا يمكنك منح أدوار لا تملكها: '.implode(', ', $offending), 403, [
                'roles' => $offending,
            ]);
        }
    }

    public static function assertNotSelf(User $actor, User $target, string $action = 'تعديل'): void
    {
        if ($actor->id === $target->id) {
            throw new ApiException('cannot_modify_self', "لا يمكنك {$action} حسابك الخاص", 422);
        }
    }
}
