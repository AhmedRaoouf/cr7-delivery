<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateAdminUserAction;
use App\Actions\Admin\ResetUserPasswordAction;
use App\Enums\AdminScopeType;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminUserRequest;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\Auth\Sessions;
use App\Support\PermissionEscalationGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §7 / doc 24 §1 — admin accounts, roles, scope. Reachable by global-scope admins only (doc 08 §7 note). */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->where('type', UserType::Admin)
            ->with(['roles', 'adminProfile', 'adminScopes'])
            ->when($request->string('filter.role')->isNotEmpty(), fn ($q) => $q->whereHas('roles', fn ($rq) => $rq->where('name', $request->string('filter.role'))))
            ->when($request->string('filter.status')->value() === 'suspended', fn ($q) => $q->whereHas('adminProfile', fn ($pq) => $pq->whereNotNull('suspended_at')))
            ->when($request->string('filter.status')->value() === 'invited', fn ($q) => $q->whereHas('adminProfile', fn ($pq) => $pq->whereNull('accepted_at')))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($users->items(), meta: [
            'current_page' => $users->currentPage(),
            'per_page' => $users->perPage(),
            'total' => $users->total(),
            'last_page' => $users->lastPage(),
        ]);
    }

    public function store(StoreAdminUserRequest $request, CreateAdminUserAction $action): JsonResponse
    {
        $result = $action->execute($request->user(), $request->validated());

        return ApiResponse::success([
            'user' => $result['user'],
            'temp_password' => $result['temp_password'],
        ], 'تم إنشاء حساب الأدمن بنجاح', status: 201);
    }

    public function show(User $user): JsonResponse
    {
        abort_unless($user->type === UserType::Admin, 404);

        return ApiResponse::success($user->load(['roles', 'permissions', 'adminProfile', 'adminScopes']));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($user->type === UserType::Admin, 404);
        PermissionEscalationGuard::assertCanManage($request->user(), $user);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:users,email,'.$user->id],
            'job_title' => ['nullable', 'string', 'max:255'],
        ]);

        $user->update(collect($validated)->except('job_title')->all());

        if (array_key_exists('job_title', $validated)) {
            $user->adminProfile()->update(['job_title' => $validated['job_title']]);
        }

        return ApiResponse::success($user->fresh(), 'تم تحديث بيانات الأدمن بنجاح');
    }

    public function updateRoles(Request $request, User $user): JsonResponse
    {
        abort_unless($user->type === UserType::Admin, 404);
        PermissionEscalationGuard::assertNotSelf($request->user(), $user, 'تغيير دور');
        PermissionEscalationGuard::assertCanManage($request->user(), $user);

        $validated = $request->validate(['roles' => ['required', 'array'], 'roles.*' => ['string', 'exists:roles,name']]);

        PermissionEscalationGuard::assertCanGrantRoles($request->user(), $validated['roles']);

        $user->syncRoles($validated['roles']);
        AuditLogger::record($request->user(), 'roles.manage', $user, $validated);

        return ApiResponse::success($user->fresh(['roles']), 'تم تحديث أدوار الأدمن بنجاح');
    }

    public function updatePermissions(Request $request, User $user): JsonResponse
    {
        abort_unless($user->type === UserType::Admin, 404);

        $validated = $request->validate([
            'grants' => ['nullable', 'array'],
            'grants.*' => ['string', 'exists:permissions,name'],
            'revokes' => ['nullable', 'array'],
            'revokes.*' => ['string', 'exists:permissions,name'],
        ]);

        PermissionEscalationGuard::assertCanManage($request->user(), $user);
        PermissionEscalationGuard::assertCanGrantPermissions($request->user(), $validated['grants'] ?? []);
        PermissionEscalationGuard::assertRevocable($user, $validated['revokes'] ?? []);

        $user->givePermissionTo($validated['grants'] ?? []);
        $user->revokePermissionTo($validated['revokes'] ?? []);

        AuditLogger::record($request->user(), 'roles.manage', $user, $validated);

        return ApiResponse::success($user->fresh(['permissions']), 'تم تحديث صلاحيات الأدمن بنجاح');
    }

    public function updateScope(Request $request, User $user): JsonResponse
    {
        abort_unless($user->type === UserType::Admin, 404);
        // Batch 67 — nobody moves their own scope (doc 12 §10.2 rule 2), and nobody moves
        // the scope of an account that holds more than they do.
        PermissionEscalationGuard::assertNotSelf($request->user(), $user, 'تغيير نطاق');
        PermissionEscalationGuard::assertCanManage($request->user(), $user);

        if ($user->hasRole('super_admin')) {
            throw new ApiException('super_admin_is_global', 'حساب super_admin دايمًا نطاقه عالمي', 422);
        }

        $validated = $request->validate([
            'scope_type' => ['required', 'in:global,city,zone'],
            'city_ids' => ['nullable', 'array'],
            'city_ids.*' => ['integer', 'exists:cities,id'],
            'zone_ids' => ['nullable', 'array'],
            'zone_ids.*' => ['integer', 'exists:service_zones,id'],
        ]);

        $user->adminScopes()->delete();

        if ($validated['scope_type'] === 'global') {
            $user->adminScopes()->create(['scope_type' => AdminScopeType::Global]);
        } else {
            foreach ($validated['city_ids'] ?? [] as $cityId) {
                $user->adminScopes()->create(['scope_type' => AdminScopeType::City, 'city_id' => $cityId]);
            }
            foreach ($validated['zone_ids'] ?? [] as $zoneId) {
                $user->adminScopes()->create(['scope_type' => AdminScopeType::Zone, 'zone_id' => $zoneId]);
            }
        }

        AuditLogger::record($request->user(), 'scope.manage', $user, $validated);

        return ApiResponse::success($user->fresh(['adminScopes']), 'تم تحديث نطاق الأدمن بنجاح');
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        abort_unless($user->type === UserType::Admin, 404);
        PermissionEscalationGuard::assertNotSelf($request->user(), $user, 'إيقاف');
        PermissionEscalationGuard::assertCanManage($request->user(), $user);
        $this->assertNotLastSuperAdmin($user);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $user->update(['is_active' => false]);
        $user->adminProfile()->update(['suspended_at' => now(), 'suspended_by' => $request->user()->id, 'suspend_reason' => $validated['reason']]);
        Sessions::revokeAll($user);

        AuditLogger::record($request->user(), 'users.suspend', $user, $validated);

        return ApiResponse::success($user->fresh(), 'تم إيقاف الحساب');
    }

    public function restore(Request $request, User $user): JsonResponse
    {
        abort_unless($user->type === UserType::Admin, 404);
        PermissionEscalationGuard::assertCanManage($request->user(), $user);

        $user->update(['is_active' => true]);
        $user->adminProfile()->update(['suspended_at' => null, 'suspended_by' => null, 'suspend_reason' => null]);

        AuditLogger::record($request->user(), 'users.restore', $user);

        return ApiResponse::success($user->fresh(), 'تم إعادة تفعيل الحساب');
    }

    public function resetPassword(Request $request, User $user, ResetUserPasswordAction $action): JsonResponse
    {
        abort_unless($user->type === UserType::Admin, 404);
        // Batch 67 — the temporary password comes back in this response, so resetting an
        // account is taking it over. Only over an account that holds no more than you;
        // your own password is changed through `/auth/password/change`, with its code.
        PermissionEscalationGuard::assertNotSelf($request->user(), $user, 'إعادة تعيين كلمة مرور');
        PermissionEscalationGuard::assertCanManage($request->user(), $user);

        $tempPassword = $action->execute($user);
        AuditLogger::record($request->user(), 'users.manage', $user, ['action' => 'reset_password']);

        return ApiResponse::success(['temp_password' => $tempPassword], 'تم إعادة تعيين كلمة المرور بنجاح');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_unless($user->type === UserType::Admin, 404);
        PermissionEscalationGuard::assertNotSelf($request->user(), $user, 'حذف');
        PermissionEscalationGuard::assertCanManage($request->user(), $user);
        $this->assertNotLastSuperAdmin($user);

        // A soft-deleted account cannot authenticate, but its sessions would sit in the
        // tables as live credentials for a row that an undelete would bring back.
        Sessions::revokeAll($user);
        $user->delete();
        AuditLogger::record($request->user(), 'users.manage', $user, ['action' => 'delete']);

        return ApiResponse::success(message: 'تم حذف الحساب');
    }

    private function assertNotLastSuperAdmin(User $user): void
    {
        if (! $user->hasRole('super_admin')) {
            return;
        }

        $activeSuperAdmins = User::role('super_admin')->where('is_active', true)->count();

        if ($activeSuperAdmins <= 1) {
            throw new ApiException('last_super_admin', 'لا يمكن إزالة آخر super_admin نشط', 422);
        }
    }
}
