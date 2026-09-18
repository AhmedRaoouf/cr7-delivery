<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;

/**
 * doc 08 §3 — POST /auth/password/forgot. Reuses the OTP channel (purpose = reset) since
 * every account has a phone.
 *
 * The login is a phone for every account type. D-107 let an admin ask by email and answered
 * with the phone masked, «‪010•••••99‬»; the operator removed the email on 2026-09-17, so the
 * caller always typed the number the code goes to and there is nothing to mask. An admin's
 * code is still a real SMS whatever `otp.mode` says — that half of D-107 stands.
 */
class ForgotPasswordAction
{
    public function __construct(private readonly SendOtpAction $sendOtp) {}

    public function execute(string $login): void
    {
        $user = User::query()
            ->whereLogin($login)
            ->first();

        // `! $user->password` used to be part of this. Since 2026-09-07 every customer
        // signs in with one, and the accounts that predate that change have none — so the
        // guard silently refused a code to precisely the people who needed one to set
        // their first password. Only «no such account» is a reason to do nothing, and it
        // is still answered with the same success message either way (doc 08 §1).
        if (! $user) {
            // Batch 67 — the same clocks as a real send, so the answer cannot tell a
            // stranger's number from a registered one. See {@see SendOtpAction::shadow()}.
            $this->sendOtp->shadow($login, OtpPurpose::Reset);

            return;
        }

        $this->sendOtp->execute($user->phone, OtpPurpose::Reset, $user);
    }
}
