<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\PermissionEscalationGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** doc 08 §7 / doc 24 §1.3 — seeded roles (is_system) are readable and cloneable, never editable or deletable. */
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        // Not ->withCount('users'): spatie's Role::users() resolves the related model via
        // getModelForGuard() against an unhydrated instance when the eager-count subquery is
        // built, which returns null here and blows up morphedByMany(). Count model_has_roles directly.
        $assignedCounts = DB::table('model_has_roles')->select('role_id', DB::raw('count(*) as count'))->groupBy('role_id')->pluck('count', 'role_id');

        $roles = Role::query()->with('permissions')->get()->map(fn ($role) => [
            'id' => $role->id,
            'name' => $role->name,
            'label_ar' => $role->label_ar,
            'label_en' => $role->label_en,
            'description' => $role->description,
            'is_system' => $role->is_system,
            'assigned_count' => $assignedCounts[$role->id] ?? 0,
            'permissions' => $role->permissions->pluck('name'),
        ]);

        return ApiResponse::success($roles);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:roles,name'],
            'label_ar' => ['required', 'string', 'max:255'],
            'label_en' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        PermissionEscalationGuard::assertCanGrantPermissions($request->user(), $validated['permissions'] ?? []);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web',
            'label_ar' => $validated['label_ar'],
            'label_en' => $validated['label_en'],
            'description' => $validated['description'] ?? null,
            'is_system' => false,
            'created_by' => $request->user()->id,
        ]);

        $role->syncPermissions($validated['permissions'] ?? []);

        // `syncPermissions()` writes `role_has_permissions`, so no model event fires on
        // `Role` and AuditableObserver records the row's columns without the one thing
        // that matters about it. Which permissions a role carries is the most consequential
        // admin action there is — it is named here, in its own row.
        AuditLogger::record($request->user(), 'roles.permissions', $role, ['permissions' => $validated['permissions'] ?? []]);

        return ApiResponse::success($role->fresh('permissions'), 'تم إنشاء الدور بنجاح', status: 201);
    }

    public function show(Role $role): JsonResponse
    {
        return ApiResponse::success($role->load('permissions'));
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        if ($role->is_system) {
            throw new ApiException('system_role', 'لا يمكن تعديل دور أساسي، انسخه أولًا', 422);
        }

        $validated = $request->validate([
            'label_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'label_en' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        if (array_key_exists('permissions', $validated)) {
            PermissionEscalationGuard::assertCanGrantPermissions($request->user(), $validated['permissions']);

            $before = $role->permissions->pluck('name')->all();
            $role->syncPermissions($validated['permissions']);

            // Pivot write — invisible to the observer. Both sides are recorded, because
            // «what was taken away» is as much of the answer as «what was granted».
            AuditLogger::record(
                $request->user(),
                'roles.permissions',
                $role,
                ['permissions' => $validated['permissions']],
                ['permissions' => $before],
            );
        }

        $role->update(collect($validated)->except('permissions')->all());

        return ApiResponse::success($role->fresh('permissions'), 'تم تحديث الدور بنجاح');
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->is_system) {
            throw new ApiException('system_role', 'لا يمكن حذف دور أساسي', 422);
        }

        if (DB::table('model_has_roles')->where('role_id', $role->id)->exists()) {
            throw new ApiException('role_in_use', 'هذا الدور مُسند لمستخدمين حاليًا', 422);
        }

        $role->delete();

        return ApiResponse::success(message: 'تم حذف الدور بنجاح');
    }

    public function clone(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:roles,name'],
            'label_ar' => ['required', 'string', 'max:255'],
            'label_en' => ['required', 'string', 'max:255'],
        ]);

        // D-51 — «an admin can never create, or grant, a permission or role they do not
        // themselves hold». `store` and `update` both assert it and `clone` did not, so the
        // one endpoint that copies a permission set wholesale was the one that copied it
        // unchecked: any holder of `roles.manage` could mint a full-permission role off
        // `super_admin`. Nothing could then assign it — `assertCanGrantRoles` sees a role
        // the actor does not hold — but a role that exists is a role some later code path
        // can hand out, and the rule says the set is refused at creation.
        PermissionEscalationGuard::assertCanGrantPermissions($request->user(), $role->permissions->pluck('name')->all());

        $clone = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web',
            'label_ar' => $validated['label_ar'],
            'label_en' => $validated['label_en'],
            'description' => $role->description,
            'is_system' => false,
            'created_by' => $request->user()->id,
        ]);

        $permissions = $role->permissions->pluck('name');
        $clone->syncPermissions($permissions);

        AuditLogger::record(
            $request->user(),
            'roles.permissions',
            $clone,
            ['permissions' => $permissions->all(), 'cloned_from' => $role->name],
        );

        return ApiResponse::success($clone->fresh('permissions'), 'تم نسخ الدور بنجاح', status: 201);
    }
}
