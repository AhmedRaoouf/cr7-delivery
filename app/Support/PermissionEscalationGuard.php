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

    /**
     * Batch 67 — an admin may act on another admin's account only if they hold at least
     * everything that account holds.
     *
     * Nothing checked this. `users.manage` let any holder reset a `super_admin`'s
     * password and read the temporary one back from the response — a full takeover of
     * the most privileged account by a lesser one — and `users.suspend`,
     * `roles.manage` and `scope.manage` let them suspend it, strip its roles or narrow
     * its scope. D-51 already refuses a grant beyond the actor's own authority; this is
     * the same rule applied to the account being acted on. A `super_admin` passes (they
     * hold every permission); everybody else must hold a superset of the target's, and
     * nobody but a `super_admin` acts on a `super_admin`.
     *
     * @throws ApiException 403 permission_escalation, naming what the target holds beyond the actor
     */
    public static function assertCanManage(User $actor, User $target): void
    {
        if ($actor->hasRole('super_admin')) {
            return;
        }

        $held = $actor->getAllPermissions()->pluck('name')->all();
        $beyond = array_values(array_diff($target->getAllPermissions()->pluck('name')->all(), $held));

        if ($target->hasRole('super_admin') || $beyond !== []) {
            throw new ApiException(
                'permission_escalation',
                'لا يمكنك إدارة حساب يملك صلاحيات لا تملكها',
                403,
                meta: ['permissions' => $beyond, 'roles' => $target->hasRole('super_admin') ? ['super_admin'] : []],
            );
        }
    }

    /**
     * Batch 67 — `revokes` removes a **direct** grant; spatie cannot subtract a
     * permission a role carries. A revoke of a role-held permission used to answer 200
     * and change nothing, leaving an operator who was told the power was gone still
     * holding it. D-51: rejected and named, never silently trimmed.
     *
     * @param  list<string>  $revokes
     *
     * @throws ApiException 422 permission_held_by_role
     */
    public static function assertRevocable(User $target, array $revokes): void
    {
        if ($revokes === []) {
            return;
        }

        $viaRoles = $target->getPermissionsViaRoles()->pluck('name')->all();
        $offending = array_values(array_intersect($revokes, $viaRoles));

        if ($offending !== []) {
            throw new ApiException(
                'permission_held_by_role',
                'هذه الصلاحيات تأتي من دور الحساب ولا يمكن سحبها منفردة — غيّر الدور أو انسخه دون هذه الصلاحيات: '.implode(', ', $offending),
                422,
                meta: ['permissions' => $offending],
            );
        }
    }
}
