<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\ModerateAccountAction;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * D-73 — moderation for **every** account, not just admin ones.
 *
 * `Admin\UserController` has carried `suspend` / `restore` / `resetPassword` / `destroy`
 * since the beginning and every one of them opens with
 * `abort_unless($user->type === UserType::Admin, 404)`. So an operator could suspend a
 * colleague and could not do anything at all about the customer who was abusing a
 * restaurant, or the captain a customer had complained about twice. The verbs existed;
 * they were pointed at the one population that needed them least.
 *
 * This is the same four verbs against a customer, a captain or a restaurant owner.
 * Admin accounts are **excluded** here on purpose — they keep their own route, with the
 * role-escalation guard and the last-super-admin rule that only apply to them.
 */
class AccountModerationController extends Controller
{
    public function block(Request $request, User $user, ModerateAccountAction $action): JsonResponse
    {
        $this->assertModerable($user);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return ApiResponse::success(
            $action->block($request->user(), $user, $validated['reason']),
            'تم إيقاف الحساب — لن يتمكن من الدخول',
        );
    }

    public function unblock(Request $request, User $user, ModerateAccountAction $action): JsonResponse
    {
        $this->assertModerable($user);

        return ApiResponse::success($action->unblock($request->user(), $user), 'تم إعادة تفعيل الحساب');
    }

    public function reset(Request $request, User $user, ModerateAccountAction $action): JsonResponse
    {
        $this->assertModerable($user);

        $password = $action->reset($request->user(), $user);

        return ApiResponse::success(
            ['temp_password' => $password],
            'تم إعادة تعيين الحساب — سلّم كلمة المرور المؤقتة لصاحبه',
        );
    }

    public function destroy(Request $request, User $user, ModerateAccountAction $action): JsonResponse
    {
        $this->assertModerable($user);

        $action->delete($request->user(), $user);

        return ApiResponse::success(message: 'تم حذف الحساب نهائيًا من التطبيق');
    }

    /** Admin accounts have their own route and their own guards; everything else lands here. */
    private function assertModerable(User $user): void
    {
        abort_if($user->type === UserType::Admin, 404);
    }
}
