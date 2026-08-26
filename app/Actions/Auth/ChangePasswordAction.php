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

        $user->forceFill([
            'password' => Hash::make($newPassword),
            'must_change_password' => false,
        ])->save();
    }
}
