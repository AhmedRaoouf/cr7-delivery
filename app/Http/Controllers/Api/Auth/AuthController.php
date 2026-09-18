<?php

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\ChangePasswordAction;
use App\Actions\Auth\DeleteAccountAction;
use App\Actions\Auth\ForgotPasswordAction;
use App\Actions\Auth\ResetPasswordAction;
use App\Actions\Auth\SendOtpAction;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Api\Shared\DeviceController;
use App\Http\Controllers\Concerns\HandlesUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\DeleteAccountRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateMeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Auth\Sessions;
use App\Support\OtpRequester;
use App\Support\PhoneNumber;
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
        $data = $this->withPublicUpload($request->validated(), $request, 'avatar', 'avatars', $request->user());
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
    private function notificationPreferences(User $user): array
    {
        return [
            'order_updates' => (bool) $user->notify_orders,
            'captain_messages' => (bool) $user->notify_captain,
            'promotions' => (bool) $user->notify_promos,
        ];
    }

    /**
     * Signing out also unregisters the phone.
     *
     * The client calls `DELETE /devices` first, but four of the app's five sign-out
     * buttons never called anything at all — so the device row outlived the session and
     * the next person to sign in on that phone kept receiving the previous account's
     * pushes (operator, 2026-09-11). Doing it here as well means a sign-out that reaches
     * the server is a sign-out that stops the notifications, whatever the client did.
     */
    public function logout(Request $request): JsonResponse
    {
        self::endCaptainShift($request->user());

        DeviceController::forget(
            $request->user(),
            $request->header('X-Device-Id') ?? $request->string('device_id')->value(),
            $request->string('token')->value(),
        );

        // This sign-in, both halves (D-120): the access token and every refresh token
        // descended from the same sign-in, so no renewal can bring it back.
        Sessions::endCurrent($request->user());

        return ApiResponse::success(message: 'تم تسجيل الخروج');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        self::endCaptainShift($request->user());
        $request->user()->deviceTokens()->delete();
        Sessions::revokeAll($request->user());

        return ApiResponse::success(message: 'تم تسجيل الخروج من كل الأجهزة');
    }

    /**
     * Signing out ends the shift.
     *
     * `is_online` was only ever written by `POST /driver/status`, so a captain who
     * signed out stayed online in the database for good: `ADM-19` showed them «متصل
     * ومتفرغ», the fleet map put a marker where they last were, and `DispatchService`
     * kept offering them orders that no longer had anywhere to arrive. Nothing expired
     * it either — the 90-second staleness rule only downgrades a captain to «موقعه
     * قديم», it never takes them offline.
     *
     * The trip is deliberately untouched (doc 63 §7): a captain who signs out mid-delivery
     * still carries every order on it, and taking one away here would silently unassign
     * it outside `UnassignDriverAction` and its audit trail. `is_available` and
     * `active_order_id` are not written either — they are derived from those orders by
     * `DriverTrip::refresh`, never by hand.
     */
    private static function endCaptainShift(User $user): void
    {
        $user->driver?->update(['is_online' => false, 'online_since' => null]);
    }

    /**
     * The same sentence whether or not the account exists (doc 08 §1) — and, since batch 68,
     * the same `data` too: `{resend_in, expires_in, code_length, max_attempts, static_otp}`,
     * with every clock behind it running for a stranger's number exactly as for a registered
     * one ({@see SendOtpAction::shadow()}). `static_otp` is always null
     * here — see {@see ForgotPasswordAction::execute()}.
     */
    public function forgotPassword(ForgotPasswordRequest $request, ForgotPasswordAction $action): JsonResponse
    {
        $sent = $action->execute($request->string('login')->value(), OtpRequester::fromRequest($request));

        return ApiResponse::success($sent, 'إذا كان الحساب موجودًا، سيصلك رمز التحقق');
    }

    public function resetPassword(ResetPasswordRequest $request, ResetPasswordAction $action): JsonResponse
    {
        $action->execute($request->string('login'), $request->string('code'), $request->string('password'));

        return ApiResponse::success(message: 'تم إعادة تعيين كلمة المرور بنجاح');
    }

    /**
     * D-107 — the code `password/change` asks an admin for, sent to the phone on the
     * signed-in account. A real SMS always: `password_change` is a purpose test mode
     * never reaches, so `static_otp` is not part of this answer.
     */
    public function sendPasswordChangeCode(Request $request, SendOtpAction $action): JsonResponse
    {
        $user = $request->user();

        $sent = $action->execute($user->phone, OtpPurpose::PasswordChange, $user, OtpRequester::fromRequest($request));

        // Batch 68 — the send shape every other code answers, plus where it went.
        // `static_otp` is always null: a password-change code is never the test code.
        return ApiResponse::success(['masked_phone' => PhoneNumber::mask($user->phone)] + $sent, 'تم إرسال رمز التحقق إلى هاتفك');
    }

    public function changePassword(ChangePasswordRequest $request, ChangePasswordAction $action): JsonResponse
    {
        $action->execute(
            $request->user(),
            $request->string('current_password'),
            $request->string('password'),
            $request->string('code')->value() ?: null,
        );

        return ApiResponse::success(message: 'تم تغيير كلمة المرور بنجاح');
    }

    public function deleteAccount(DeleteAccountRequest $request, DeleteAccountAction $action): JsonResponse
    {
        $action->execute($request->user(), $request->string('reason')->value() ?: null);

        return ApiResponse::success(message: 'تم حذف الحساب بنجاح');
    }
}
