<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** doc 08 §3 — POST /auth/password/reset. Consumes the `reset`-purpose OTP sent to the account's phone. */
class ResetPasswordAction
{
    public function execute(string $login, string $code, string $password): void
    {
        DB::transaction(function () use ($login, $code, $password) {
            $user = User::query()
                ->whereLogin($login)
                ->first();

            // `! $user->password` used to be part of this guard, which meant every
            // account created before passwords existed — i.e. every customer — was told
            // its own reset code was wrong. Setting a first password is exactly what
            // this endpoint is for; only a phone with no account at all is refused, and
            // it is refused with the code's message so the endpoint does not become a
            // way to ask which numbers are registered.
            if (! $user) {
                throw new ApiException('otp_invalid', 'رمز التحقق غير صحيح', 422);
            }

            $otp = OtpCode::query()
                ->where('phone', $user->phone)
                ->where('purpose', OtpPurpose::Reset)
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

            $user->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => false,
            ])->save();

            $user->tokens()->delete();
        });
    }
}
