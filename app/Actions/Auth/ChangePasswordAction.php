<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Auth\Sessions;
use App\Support\OtpVerifier;
use Illuminate\Support\Facades\Hash;

/**
 * doc 08 §3 — POST /auth/password/change. Clears must_change_password (FR-AUTH-10).
 *
 * D-107 — an admin changes a password only with a code sent by SMS to the admin's own
 * phone, including the forced first change. The operator: «لو عايز يغير كلمه السر بقا
 * تبقا ب sms … ويبقا دا الوحيد اللي مفيش فيه غير sms في اي حاله». The current password
 * alone is what a stolen session or a password read over a shoulder already has. For
 * everyone else `code` is ignored.
 */
class ChangePasswordAction
{
    public function execute(User $user, string $currentPassword, string $newPassword, ?string $code = null): void
    {
        if (! $user->password || ! Hash::check($currentPassword, $user->password)) {
            throw new ApiException('invalid_credentials', 'كلمة المرور الحالية غير صحيحة', 422);
        }

        // The new one may not be the old one.
        //
        // Without this, `must_change_password` was a formality: an account created by an
        // admin could satisfy the forced change by retyping the password the admin had
        // just read off their own screen, and the credential the operator handed over in
        // a WhatsApp message stayed live. Compared with `Hash::check` rather than by
        // string equality — the stored value is a hash, and `===` against it is always
        // false, which is exactly how this check gets written and never fires.
        if (Hash::check($newPassword, $user->password)) {
            throw new ApiException(
                'password_unchanged',
                'كلمة المرور الجديدة يجب أن تختلف عن الحالية',
                422,
            );
        }

        // Checked last, so a wrong current password never spends the code, and before the
        // write, so a wrong code changes nothing. {@see OtpVerifier} commits the attempt.
        if ($user->isAdmin()) {
            $sendFirst = new ApiException('otp_required', 'أرسل رمز التحقق إلى هاتفك أولًا', 422);

            if (blank($code)) {
                throw $sendFirst;
            }

            OtpVerifier::consume($user->phone, OtpPurpose::PasswordChange, (string) $code, whenNone: $sendFirst);
        }

        $user->forceFill([
            'password' => Hash::make($newPassword),
            'must_change_password' => false,
        ])->save();

        // D-120 — every other session ends; this one stays. It revoked nothing before, so
        // a password changed because it had leaked left every session made with the leaked
        // one working. The sign-in that typed the new password is the one known to hold it.
        Sessions::revokeOthers($user);
    }
}
