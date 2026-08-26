<?php

namespace App\Actions\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** restaurants.reset_password / drivers.reset_password — issues a new temp password the owner must change on next login. */
class ResetUserPasswordAction
{
    public function execute(User $user): string
    {
        $tempPassword = Str::password(12);

        $user->update([
            'password' => Hash::make($tempPassword),
            'must_change_password' => true,
        ]);

        return $tempPassword;
    }
}
