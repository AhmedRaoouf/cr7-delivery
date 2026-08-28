<?php

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\ChangePasswordAction;
use App\Actions\Auth\DeleteAccountAction;
use App\Actions\Auth\ForgotPasswordAction;
use App\Actions\Auth\ResetPasswordAction;
use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\DeleteAccountRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateMeRequest;
use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** doc 08 §3 — shared auth concerns across every client: session, profile, password lifecycle. */
class AuthController extends Controller
{
    use HandlesUploads;

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()));
    }

    public function updateMe(UpdateMeRequest $request): JsonResponse
    {
        $data = $this->withPublicUpload($request->validated(), $request, 'avatar', 'avatars');
        $request->user()->update($data);

        return ApiResponse::success(new UserResource($request->user()->fresh()), 'تم تحديث البيانات بنجاح');
    }

    /**
     * CUS-44 — the three notification toggles, kept on the account rather than on the
     * device so a reinstall cannot silently re-enable a channel the customer turned
     * off, and so the sender can honour them.
     */
    public function notificationSettings(Request $request): JsonResponse
    {
        return ApiResponse::success($this->notificationPreferences($request->user()));
    }

    public function updateNotificationSettings(Request $request): JsonResponse
    {
        // The wire names are the client's own — `NotificationPrefsModel` uses
        // them for the on-device copy too, so one vocabulary covers both.
        $validated = $request->validate([
            'order_updates' => ['sometimes', 'boolean'],
            'captain_messages' => ['sometimes', 'boolean'],
            'promotions' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();

        $user->update(array_filter([
            'notify_orders' => $validated['order_updates'] ?? null,
            'notify_captain' => $validated['captain_messages'] ?? null,
            'notify_promos' => $validated['promotions'] ?? null,
        ], static fn ($value) => $value !== null));

        return ApiResponse::success($this->notificationPreferences($user->fresh()), 'تم حفظ التفضيلات');
    }

    /** @return array<string, bool> */
    private function notificationPreferences(\App\Models\User $user): array
    {
        return [
            'order_updates' => (bool) $user->notify_orders,
            'captain_messages' => (bool) $user->notify_captain,
            'promotions' => (bool) $user->notify_promos,
        ];
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(message: 'تم تسجيل الخروج');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return ApiResponse::success(message: 'تم تسجيل الخروج من كل الأجهزة');
    }

    public function forgotPassword(ForgotPasswordRequest $request, ForgotPasswordAction $action): JsonResponse
    {
        $action->execute($request->string('login'));

        return ApiResponse::success(message: 'إذا كان الحساب موجودًا، سيصلك رمز التحقق');
    }

    public function resetPassword(ResetPasswordRequest $request, ResetPasswordAction $action): JsonResponse
    {
        $action->execute($request->string('login'), $request->string('code'), $request->string('password'));

        return ApiResponse::success(message: 'تم إعادة تعيين كلمة المرور بنجاح');
    }

    public function changePassword(ChangePasswordRequest $request, ChangePasswordAction $action): JsonResponse
    {
        $action->execute($request->user(), $request->string('current_password'), $request->string('password'));

        return ApiResponse::success(message: 'تم تغيير كلمة المرور بنجاح');
    }

    public function deleteAccount(DeleteAccountRequest $request, DeleteAccountAction $action): JsonResponse
    {
        $action->execute($request->user(), $request->string('reason')->value() ?: null);

        return ApiResponse::success(message: 'تم حذف الحساب بنجاح');
    }
}
