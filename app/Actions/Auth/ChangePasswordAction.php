<?php

namespace App\Actions\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/** doc 08 §3 — POST /auth/password/change. Clears must_change_password (FR-AUTH-10). */
class ChangePasswordAction
{
    public function execute(User $user, string $currentPassword, string $newPassword): void
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

        $user->forceFill([
            'password' => Hash::make($newPassword),
            'must_change_password' => false,
        ])->save();
    }
}
