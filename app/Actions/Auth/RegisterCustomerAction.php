<?php

namespace App\Actions\Auth;

use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * «إنشاء حساب» — the second half of registration.
 *
 * The customer has already proved the phone is theirs (`VerifyOtpAction` with
 * `purpose = register`) and is holding the token that says so. This turns that token,
 * a name and a password into an account and a session, once.
 *
 * The token is destroyed inside the same transaction that creates the user, so two taps
 * on a slow «تسجيل» button cannot produce two accounts — the second one finds nothing to
 * spend and is told the link expired rather than colliding on `users.phone` with a 500.
 */
class RegisterCustomerAction
{
    /** @return array{user: User, token: string} */
    public function execute(string $registrationToken, string $name, string $password): array
    {
        return DB::transaction(function () use ($registrationToken, $name, $password): array {
            $otp = OtpCode::query()
                ->where('follow_up_token', $registrationToken)
                ->lockForUpdate()
                ->first();

            if ($otp === null || ! $otp->followUpIsUsable()) {
                throw new ApiException(
                    'registration_expired',
                    'انتهت صلاحية التحقق — أعد إرسال رمز التحقق',
                    422,
                );
            }

            // Re-checked here, not only in `VerifyOtpAction`. Fifteen minutes is long
            // enough for the same number to be registered from another device, and the
            // unique index would otherwise answer that race with a 500.
            $existing = User::query()->where('phone', $otp->phone)->first();

            if ($existing !== null) {
                throw new ApiException(
                    'phone_taken',
                    "هذا الرقم مسجل بالفعل كـ{$existing->type->label()} — سجّل الدخول بكلمة المرور",
                    409,
                    meta: ['account_type' => $existing->type->value],
                );
            }

            // `forceFill`, not `create`: `phone_verified_at` and `last_login_at` are not
            // in `$fillable` — deliberately, so no client request can ever set them — and
            // a mass-assign silently dropped both, leaving a brand-new account looking
            // unverified to every guard that reads it.
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'phone' => $otp->phone,
                'password' => Hash::make($password),
                'type' => UserType::Customer,
                'locale' => 'ar',
                'theme' => 'dark',
                'is_active' => true,
                'must_change_password' => false,
                'phone_verified_at' => now(),
                'last_login_at' => now(),
            ])->save();

            $otp->forceFill(['follow_up_token' => null, 'follow_up_expires_at' => null])->save();

            $token = $user->createToken('customer', [$user->type->value])->plainTextToken;

            return ['user' => $user, 'token' => $token];
        });
    }
}
