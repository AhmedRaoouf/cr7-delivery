<?php

namespace App\Actions\Admin;

use App\Models\User;
use App\Support\TempPassword;
use Illuminate\Support\Facades\Hash;

/** restaurants.reset_password / drivers.reset_password — issues a new temp password the owner must change on next login. */
class ResetUserPasswordAction
{
    public function execute(User $user): string
    {
        $tempPassword = TempPassword::issue();

        $user->update([
            'password' => Hash::make($tempPassword),
            'must_change_password' => true,
        ]);

        return $tempPassword;
    }
}
