<?php

namespace App\Support\Auth;

use App\Enums\UserType;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * A super admin holds every permission there is — by role, not by a list of rows
 * (operator, 2026-11-05).
 *
 * The role used to be special only because a migration handed it each permission as
 * the permission was created. Any permission a later migration forgot to attach — or
 * one attached to the role only after the admin's session began — made a screen answer
 * «ليس لديك صلاحية لهذه الشاشة» to the platform's own owner. Now the role is the
 * answer: `Gate::before` passes every registered permission for it, and `/auth/me`
 * publishes the whole catalogue, so every client draws every screen for it.
 *
 * Only **registered permission names** pass. A policy or any other ability keeps its
 * own rules, and the scope (D-50, «whose rows») is untouched.
 */
final class SuperAdmin
{
    public const ROLE = 'super_admin';

    public static function is(?User $user): bool
    {
        return $user !== null
            && $user->type === UserType::Admin
            && $user->hasRole(self::ROLE);
    }

    public static function isPermission(string $ability): bool
    {
        return app(PermissionRegistrar::class)
            ->getPermissions(['name' => $ability])
            ->isNotEmpty();
    }

    /** @return list<string> */
    public static function everyPermission(): array
    {
        return app(PermissionRegistrar::class)
            ->getPermissions()
            ->pluck('name')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
