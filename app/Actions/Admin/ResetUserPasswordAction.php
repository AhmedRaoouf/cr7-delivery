<?php

namespace App\Actions\Admin;

use App\Models\User;
use App\Support\Auth\Sessions;
use App\Support\TempPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * restaurants.reset_password / drivers.reset_password / users.manage — issues a new temp
 * password the owner must change on next login, and ends every session the account held.
 *
 * D-120 — a password the office replaces is replaced because the old one is lost or
 * leaked, and a session made with it is exactly what must not survive the replacement.
 * This revoked nothing before, so a place whose password was reset because a former
 * employee still knew it kept every device that employee had signed in on. Every session
 * goes — access and refresh tokens, legacy ones included — in the same transaction as the
 * new password, so there is no moment where both work.
 */
class ResetUserPasswordAction
{
    public function execute(User $user): string
    {
        $tempPassword = TempPassword::issue();

        DB::transaction(function () use ($user, $tempPassword) {
            $user->update([
                'password' => Hash::make($tempPassword),
                'must_change_password' => true,
            ]);

            Sessions::revokeAll($user);
        });

        return $tempPassword;
    }
}
