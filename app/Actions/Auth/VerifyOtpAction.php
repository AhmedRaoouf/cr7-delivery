<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * FR-AUTH-01/03: phone + OTP is both sign-up and login for a customer — a new
 * phone number simply creates the account on first successful verification.
 */
class VerifyOtpAction
{
    /** @return array{user: User, token: string} */
    public function execute(string $phone, string $code, OtpPurpose $purpose): array
    {
        return DB::transaction(function () use ($phone, $code, $purpose) {
            $otp = OtpCode::query()
                ->where('phone', $phone)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $otp) {
                throw new ApiException('otp_invalid', 'رمز التحقق غير صحيح', 422);
            }

            if ($otp->expires_at->isPast()) {
                throw new ApiException('otp_expired', 'انتهت صلاحية رمز التحقق', 422);
            }

            if ($otp->attempts >= 5) {
                throw new ApiException('otp_attempts_exceeded', 'تم تجاوز عدد المحاولات المسموح بها', 423);
            }

            if (! Hash::check($code, $otp->code_hash)) {
                $otp->increment('attempts');

                throw new ApiException('otp_invalid', 'رمز التحقق غير صحيح', 422, meta: ['attempts_left' => 5 - $otp->attempts]);
            }

            $otp->update(['consumed_at' => now()]);

            $user = User::query()->firstOrCreate(
                ['phone' => $phone],
                ['type' => UserType::Customer, 'is_active' => true, 'locale' => 'ar', 'theme' => 'dark'],
            );

            if (! $user->isCustomer()) {
                throw new ApiException('wrong_client', 'هذا الرقم مسجل بنوع حساب مختلف', 403);
            }

            if (! $user->is_active) {
                throw new ApiException('account_suspended', 'هذا الحساب موقوف', 423);
            }

            $user->forceFill(['phone_verified_at' => now(), 'last_login_at' => now()])->save();

            $token = $user->createToken('customer', [$user->type->value])->plainTextToken;

            return ['user' => $user, 'token' => $token];
        });
    }
}
